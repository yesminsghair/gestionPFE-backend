<?php
namespace App\Http\Controllers;

use App\Models\Soutenance;
use App\Models\JuryMembrePfe;
use App\Models\NotePfe;
use App\Models\ProjetPfe;
use App\Models\ResultatPfe;
use App\Models\Notification;
use App\Models\Utilisateur;
use App\Models\NoteGrilleEvaluation;   // per-critere note detail (optional -- see note below)
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * JuryPfeController
 *
 * Each student/project is evaluated by EXACTLY 3 jury members (encadrant +
 * 2 examinateurs / président). Each of those 3 members submits a NotePfe row
 * (plus optional per-critère rows in notes_grille_pfe).
 *
 * Final result = average of the 3 finalised NotePfe.note values.
 */
class JuryPfeController extends Controller
{
    // ── CRUD ──────────────────────────────────────────────────────

    public function index(): JsonResponse
    {
        $jurys = Soutenance::with([
            'projet.etudiant',
            'projet.encadrant',
            'membres.enseignant',
            'resultat',
        ])->get()->map(fn($j) => $this->format($j));

        return response()->json($jurys);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'projet_id' => 'required|exists:projets_pfe,id|unique:soutenances,projet_id',
        ]);

        $jury = Soutenance::create(['projet_id' => $data['projet_id'], 'statut' => 'en_attente']);

        $projet = ProjetPfe::find($data['projet_id']);
        if ($projet?->encadrant_id) {
            JuryMembrePfe::create([
                'jury_id'       => $jury->id,
                'enseignant_id' => $projet->encadrant_id,
                'fonction'      => 'encadrant',
            ]);
        }

        return response()->json($jury->load('membres.enseignant'), 201);
    }

    public function show(Soutenance $juryPfe): JsonResponse
    {
        return response()->json($this->format(
            $juryPfe->load('projet.etudiant', 'projet.encadrant', 'membres.enseignant', 'resultat')
        ));
    }

    public function update(Request $request, Soutenance $juryPfe): JsonResponse
    {
        $data = $request->validate([
            'date_soutenance' => 'sometimes|date',
            'heure_debut'     => 'sometimes|date_format:H:i',
            'heure_fin'       => 'sometimes|date_format:H:i',
            'salle'           => 'sometimes|string|max:100',
            'statut'          => 'sometimes|in:en_attente,planifie,termine,annule',
        ]);

        // ── Salle conflict guard ──────────────────────────────────────────────
        // If a salle is being set or changed, verify it is not already booked
        // at the same date/time by another soutenance.
        if (!empty($data['salle']) && !empty($data['date_soutenance'])) {
            $heureDebut = $data['heure_debut'] ?? $juryPfe->heure_debut;
            $heureFin   = $data['heure_fin']   ?? $juryPfe->heure_fin;

            if ($heureDebut && $heureFin) {
                $toMin = fn(string $t): int => (int) substr($t, 0, 2) * 60 + (int) substr($t, 3, 2);

                $conflict = Soutenance::where('salle', $data['salle'])
                    ->where('date_soutenance', $data['date_soutenance'])
                    ->where('id', '!=', $juryPfe->id)
                    ->whereNotNull('heure_debut')
                    ->whereNotNull('heure_fin')
                    ->get()
                    ->first(fn($s) =>
                        $toMin($heureDebut) < $toMin($s->heure_fin) &&
                        $toMin($s->heure_debut) < $toMin($heureFin)
                    );

                if ($conflict) {
                    return response()->json([
                        'message' => "La salle \"" . $data['salle'] . "\" est deja reservee a ce creneau"
                            . " (" . $conflict->heure_debut . "-" . $conflict->heure_fin . ")."
                            . " Veuillez choisir une autre salle.",
                    ], 422);
                }
            }
        }
        // ─────────────────────────────────────────────────────────────────────

        $juryPfe->update($data);
        return response()->json($this->format($juryPfe->fresh('projet', 'membres.enseignant')));
    }

    public function destroy(Soutenance $juryPfe): JsonResponse
    {
        $juryPfe->delete();
        return response()->json(['message' => 'Jury supprimé.']);
    }

    // ── Membres ──────────────────────────────────────────────────

    public function addMembre(Request $request, Soutenance $juryPfe): JsonResponse
    {
        $data = $request->validate([
            'enseignant_id' => 'required|exists:utilisateurs,id',
            'fonction'      => 'required|in:president,encadrant,examinateur',
        ]);

        // Enforce max 3 jury members
        if ($juryPfe->membres()->count() >= 3) {
            return response()->json([
                'message' => 'Ce jury a déjà 3 membres (maximum requis pour l\'évaluation).',
            ], 422);
        }

        if ($juryPfe->membres()->where('enseignant_id', $data['enseignant_id'])->exists()) {
            return response()->json(['message' => 'Ce membre est déjà dans le jury.'], 422);
        }

        $membre = $juryPfe->membres()->create($data);

        Notification::create([
            'user_id' => $data['enseignant_id'],
            'message' => "Vous avez été affecté comme {$data['fonction']} dans un jury PFE.",
        ]);

        return response()->json($membre->load('enseignant'), 201);
    }

    public function updateMembre(Request $request, Soutenance $juryPfe, JuryMembrePfe $membre): JsonResponse
    {
        $data = $request->validate(['fonction' => 'required|in:president,encadrant,examinateur']);
        $membre->update($data);
        return response()->json($membre->load('enseignant'));
    }

    public function removeMembre(Soutenance $juryPfe, JuryMembrePfe $membre): JsonResponse
    {
        $membre->delete();
        return response()->json(['message' => 'Membre retiré.']);
    }

    // ── Notes ────────────────────────────────────────────────────

    public function getNotes(Soutenance $juryPfe): JsonResponse
    {
        return response()->json($juryPfe->notes()->with('enseignant')->get());
    }

    /**
     * POST /api/jurys-pfe/{jury}/notes
     *
     * Body:
     *   enseignant_id  int     (required)
     *   note           float   global note /20   (required)
     *   commentaire    string  (optional)
     *   finalise       bool    (optional, default false)
     *   criteres       array   optional detail per grille critère
     *     - critere_id int
     *     - note       float
     */
    public function saveNote(Request $request, Soutenance $juryPfe): JsonResponse
    {
        $data = $request->validate([
            'enseignant_id'          => 'required|exists:utilisateurs,id',
            'note'                   => 'required|numeric|min:0|max:20',
            'commentaire'            => 'nullable|string',
            'finalise'               => 'boolean',
            'criteres'               => 'nullable|array',
            'criteres.*.critere_id'  => 'required|integer',
            'criteres.*.note'        => 'required|numeric|min:0',
        ]);

        // Must be a member of this jury
        $membre = $juryPfe->membres()
            ->where('enseignant_id', $data['enseignant_id'])
            ->first();

        if (!$membre) {
            return response()->json(['message' => "Cet enseignant n'est pas membre de ce jury."], 403);
        }

        if ($membre->fonction !== 'president') {
            return response()->json(['message' => "Seul le president du jury peut soumettre une evaluation."], 403);
        }

        DB::beginTransaction();
        try {
            $note = NotePfe::updateOrCreate(
                ['jury_id' => $juryPfe->id, 'enseignant_id' => $data['enseignant_id']],
                [
                    'note'        => $data['note'],
                    'commentaire' => $data['commentaire'] ?? null,
                    'finalise'    => $data['finalise'] ?? false,
                ]
            );

            // Save per-critère details if provided
            if (!empty($data['criteres'])) {
                // notes_grille_pfe table: (note_pfe_id, critere_id, note)
                DB::table('notes_grille_pfe')->where('note_pfe_id', $note->id)->delete();
                foreach ($data['criteres'] as $c) {
                    DB::table('notes_grille_pfe')->insert([
                        'note_pfe_id' => $note->id,
                        'critere_id'  => $c['critere_id'],
                        'note'        => $c['note'],
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ]);
                }
            }

            DB::commit();
            return response()->json($note);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('saveNote: ' . $e->getMessage());
            return response()->json(['message' => 'Erreur lors de l\'enregistrement.'], 500);
        }
    }

    // ── Fiches d'évaluation (chef view) ──────────────────────────

    /**
     * GET /api/fiches-evaluation
     *
     * Returns ALL jurys in the chef's department grouped by student, with
     * each jury member's note (= "fiche") and the per-critère breakdown.
     *
     * Shape per group:
     * {
     *   jury_id, etudiant_nom, projet_titre, matricule,
     *   resultat: {note_finale, mention, decision} | null,
     *   fiches: [{
     *     id (NotePfe id),
     *     membre_jury,   // name
     *     fonction,
     *     note_totale,
     *     commentaire,
     *     date,
     *     categories: [{ id, nom, bareme, note, criteres:[{id,nom,bareme,note}] }]
     *   }]
     * }
     */
    public function fichesEvaluation(): JsonResponse
    {
        $chef = Auth::user();

        $jurys = Soutenance::with([
            'projet.etudiant',
            'membres.enseignant',
            'notes',          // NotePfe rows
            'resultat',
        ])
        ->whereHas('projet.etudiant', fn($q) => $q->where('specialite_id', $chef->specialite_id))
        ->get();

        $result = $jurys->map(function ($jury) {
            $fiches = $jury->notes->map(function ($note) use ($jury) {
                $membre = $jury->membres->firstWhere('enseignant_id', $note->enseignant_id);
                $ens    = $membre?->enseignant;

                // Load per-critère details
                $critereRows = DB::table('notes_grille_pfe as ngp')
                    ->join('criteres_evaluation as ce', 'ce.id', '=', 'ngp.critere_id')
                    ->join('categories_grille as cg', 'cg.id', '=', 'ce.categorie_id')
                    ->where('ngp.note_pfe_id', $note->id)
                    ->select('ngp.note as note_critere', 'ce.id as critere_id', 'ce.nom as critere_nom',
                             'ce.bareme_max as critere_bareme', 'cg.id as cat_id', 'cg.nom as cat_nom',
                             'cg.bareme_max as cat_bareme')
                    ->get();

                // Group by category
                $categories = $critereRows->groupBy('cat_id')->map(function ($rows, $catId) {
                    $first = $rows->first();
                    return [
                        'id'      => $catId,
                        'nom'     => $first->cat_nom,
                        'bareme'  => $first->cat_bareme,
                        'note'    => round($rows->sum('note_critere'), 2),
                        'criteres' => $rows->map(fn($r) => [
                            'id'     => $r->critere_id,
                            'nom'    => $r->critere_nom,
                            'bareme' => $r->critere_bareme,
                            'note'   => $r->note_critere,
                        ])->values(),
                    ];
                })->values();

                return [
                    'id'          => $note->id,
                    'membre_jury' => $ens ? trim($ens->prenom . ' ' . $ens->nom) : '--',
                    'fonction'    => $membre?->fonction,
                    'note_totale' => $note->note,
                    'commentaire' => $note->commentaire,
                    'finalise'    => $note->finalise,
                    'date'        => $note->updated_at?->format('d/m/Y'),
                    'categories'  => $categories,
                ];
            })->values();

            return [
                'jury_id'       => $jury->id,
                'etudiant_nom'  => trim(($jury->projet?->etudiant?->prenom ?? '') . ' ' . ($jury->projet?->etudiant?->nom ?? '')),
                'matricule'     => $jury->projet?->etudiant?->matricule ?? '--',
                'projet_titre'  => $jury->projet?->titre ?? 'Sans titre',
                'resultat'      => $jury->resultat ? [
                    'note_finale' => $jury->resultat->note_finale,
                    'mention'     => $jury->resultat->mention,
                    'decision'    => $jury->resultat->decision,
                ] : null,
                'fiches'        => $fiches,
            ];
        });

        return response()->json($result);
    }

    // ── Projets prêts à délibérer ─────────────────────────────────

    /**
     * GET /api/jurys-pfe/prets-a-deliberer
     *
     * Returns jurys that have exactly 3 finalised NotePfe but no ResultatPfe yet.
     */
    public function pretsADeliberer(): JsonResponse
    {
        $chef = Auth::user();

        $jurys = Soutenance::with(['projet.etudiant', 'notes', 'membres'])
            ->whereHas('projet.etudiant', fn($q) => $q->where('specialite_id', $chef->specialite_id))
            ->doesntHave('resultat')
            ->get()
            ->filter(function ($jury) {
                $presidentId = $jury->membres->firstWhere('fonction', 'president')?->enseignant_id;
                if (!$presidentId) return false;
                return $jury->notes->where('enseignant_id', $presidentId)->where('finalise', true)->isNotEmpty();
            });

        $result = $jurys->map(function ($jury) {
            $presidentId   = $jury->membres->firstWhere('fonction', 'president')?->enseignant_id;
            $notePresident = $jury->notes->firstWhere('enseignant_id', $presidentId);
            return [
                'jury_id'        => $jury->id,
                'etudiant_nom'   => trim(($jury->projet?->etudiant?->prenom ?? '') . ' ' . ($jury->projet?->etudiant?->nom ?? '')),
                'matricule'      => $jury->projet?->etudiant?->matricule ?? '--',
                'projet_titre'   => $jury->projet?->titre ?? 'Sans titre',
                'note_president' => $notePresident?->note,
            ];
        })->values();

        return response()->json($result);
    }

    // ── Délibération ─────────────────────────────────────────────

    /**
     * POST /api/jurys-pfe/{jury}/deliberer
     *
     * Computes the average of the 3 finalised notes and stores a ResultatPfe.
     * Fails if fewer than 3 finalised notes exist.
     */
    public function deliberer(Soutenance $juryPfe): JsonResponse
    {
        $juryPfe->load('membres');

        $presidentId = $juryPfe->membres->firstWhere('fonction', 'president')?->enseignant_id;
        if (!$presidentId) {
            return response()->json(['message' => 'Aucun president designe pour ce jury.'], 422);
        }

        $notePresident = $juryPfe->notes()
            ->where('enseignant_id', $presidentId)
            ->where('finalise', true)
            ->first();

        if (!$notePresident) {
            return response()->json(['message' => "Le president n'a pas encore soumis sa fiche d'evaluation."], 422);
        }

        $noteFinale = round((float) $notePresident->note, 2);

        $resultat = ResultatPfe::updateOrCreate(
            ['jury_id' => $juryPfe->id],
            [
                'etudiant_id' => $juryPfe->projet->etudiant_id,
                'note_finale' => $noteFinale,
                'mention'     => $this->getMention($noteFinale),
                'decision'    => $noteFinale >= 10 ? 'admis' : 'ajourne',
                'publie'      => false,
            ]
        );

        $juryPfe->update(['statut' => 'termine']);
        return response()->json($resultat);
    }

    // ── Publish / Bibliothèque / Archive ─────────────────────────

    /**
     * POST /api/jurys-pfe/{jury}/publier
     * Publishes a single result and notifies the student.
     */
    public function publier(Soutenance $juryPfe): JsonResponse
    {
        $resultat = $juryPfe->resultat;
        if (!$resultat) {
            return response()->json(['message' => 'Délibération non effectuée.'], 422);
        }

        $resultat->update(['publie' => true, 'publie_le' => now()]);

        $etudiantId  = $juryPfe->projet->etudiant_id;
        $encadrantId = $juryPfe->projet->encadrant_id;

        // Notify student
        Notification::create([
            'user_id' => $etudiantId,
            'message' => 'Vos résultats de soutenance PFE sont disponibles. Connectez-vous pour les consulter.',
        ]);

        // Notify encadrant
        if ($encadrantId) {
            $etudiant = $juryPfe->projet->etudiant;
            $nom      = trim(($etudiant->prenom ?? '') . ' ' . ($etudiant->nom ?? ''));
            Notification::create([
                'user_id' => $encadrantId,
                'message' => "Les résultats PFE de votre étudiant {$nom} ont été publiés.",
            ]);
        }

        return response()->json($resultat);
    }

    /**
     * POST /api/resultats-pfe/publier-tous
     * Publishes ALL unpublished results in the chef's department and notifies everyone.
     */
    public function publierTous(): JsonResponse
    {
        $chef = Auth::user();

        $resultats = ResultatPfe::where('publie', false)
            ->whereHas('jury.projet.etudiant', fn($q) => $q->where('specialite_id', $chef->specialite_id))
            ->with('jury.projet.etudiant', 'jury.projet.encadrant')
            ->get();

        foreach ($resultats as $r) {
            $r->update(['publie' => true, 'publie_le' => now()]);

            $etudiantId  = $r->jury->projet->etudiant_id;
            $encadrantId = $r->jury->projet->encadrant_id;
            $etudiant    = $r->jury->projet->etudiant;
            $nom         = trim(($etudiant->prenom ?? '') . ' ' . ($etudiant->nom ?? ''));

            Notification::create([
                'user_id' => $etudiantId,
                'message' => 'Vos résultats de soutenance PFE sont disponibles. Connectez-vous pour les consulter.',
            ]);

            if ($encadrantId) {
                Notification::create([
                    'user_id' => $encadrantId,
                    'message' => "Les résultats PFE de votre étudiant {$nom} ont été publiés.",
                ]);
            }
        }

        return response()->json(['message' => "{$resultats->count()} résultat(s) publiés."]);
    }

    /**
     * POST /api/resultats-pfe/{resultat}/bibliotheque
     * Marks a result as "in bibliothèque" (only Très Bien = note >= 16).
     */
    public function ajouterBibliotheque(ResultatPfe $resultat): JsonResponse
    {
        if ($resultat->note_finale < 16) {
            return response()->json(['message' => 'Seuls les projets Très Bien (≥ 16/20) peuvent être ajoutés à la bibliothèque.'], 422);
        }
        if (!$resultat->publie) {
            return response()->json(['message' => 'Le résultat doit être publié avant d\'être ajouté à la bibliothèque.'], 422);
        }

        $resultat->update(['en_biblio' => true]);
        return response()->json(['message' => 'Projet ajouté à la bibliothèque PFE.']);
    }

    /**
     * POST /api/resultats-pfe/{resultat}/archiver
     * Marks a result as archived.
     */
    public function archiver(ResultatPfe $resultat): JsonResponse
    {
        if (!$resultat->publie) {
            return response()->json(['message' => 'Le résultat doit être publié avant d\'être archivé.'], 422);
        }

        $resultat->update(['archive' => true, 'archive_le' => now()]);
        return response()->json(['message' => 'Résultat archivé.']);
    }

    /**
     * GET /api/resultats-pfe
     * All deliberated results for the chef's department.
     */
    public function allResultats(): JsonResponse
    {
        $chef = Auth::user();

        $resultats = ResultatPfe::with(['jury.projet.etudiant', 'jury.projet.encadrant'])
            ->whereHas('jury.projet.etudiant', fn($q) => $q->where('specialite_id', $chef->specialite_id))
            ->latest()
            ->get()
            ->map(fn($r) => [
                'id'           => $r->id,
                'jury_id'      => $r->jury_id,
                'etudiant_nom' => trim(($r->jury->projet->etudiant->prenom ?? '') . ' ' . ($r->jury->projet->etudiant->nom ?? '')),
                'projet_titre' => $r->jury->projet->titre,
                'note_finale'  => $r->note_finale,
                'mention'      => $r->mention,
                'decision'     => $r->decision,
                'publie'       => $r->publie,
                'en_biblio'    => $r->en_biblio ?? false,
                'archive'      => $r->archive ?? false,
            ]);

        return response()->json($resultats);
    }

    // ── Student view ──────────────────────────────────────────────

    public function monResultat(Request $request): JsonResponse
    {
        $etudiantId = $request->user()->id;

        $resultat = ResultatPfe::whereHas('jury.projet', fn($q) =>
            $q->where('etudiant_id', $etudiantId)
        )->where('publie', true)->with('jury.projet')->first();

        if (!$resultat) return response()->json(null);

        return response()->json([
            'note_finale'  => $resultat->note_finale,
            'mention'      => $resultat->mention,
            'decision'     => $resultat->decision,
            'publie_le'    => $resultat->publie_le?->format('d/m/Y'),
            'projet_titre' => $resultat->jury->projet->titre,
        ]);
    }

    // ── Calendrier ────────────────────────────────────────────────

    public function publierCalendrier(): JsonResponse
    {
        $jurys = Soutenance::with(['projet.etudiant', 'membres'])
            ->where('statut', 'planifie')
            ->whereNotNull('date_soutenance')
            ->get();

        foreach ($jurys as $jury) {
            $msg = "Soutenance planifiée le {$jury->date_soutenance} à {$jury->heure_debut} en salle {$jury->salle}.";
            Notification::create(['user_id' => $jury->projet->etudiant_id, 'message' => $msg]);
            foreach ($jury->membres as $m) {
                Notification::create(['user_id' => $m->enseignant_id, 'message' => $msg]);
            }
        }

        Soutenance::where('statut', 'planifie')->update(['calendrier_publie' => true]);
        return response()->json(['message' => 'Calendrier publié.']);
    }

    // ── Chef helpers ──────────────────────────────────────────────

    public function projetsDisponibles(): JsonResponse
    {
        $chef = Auth::user();

        $projets = ProjetPfe::whereDoesntHave('jury')
            ->whereHas('etudiant', fn($q) => $q->where('specialite_id', $chef->specialite_id))
            ->with('etudiant', 'encadrant')
            ->get()
            ->map(fn($p) => [
                'id'            => $p->id,
                'titre'         => $p->titre ?? 'Sans titre',
                'etudiant_nom'  => trim(($p->etudiant->prenom ?? '') . ' ' . ($p->etudiant->nom ?? '')),
                'encadrant_nom' => trim(($p->encadrant->prenom ?? '') . ' ' . ($p->encadrant->nom ?? '')),
                'specialite'    => $p->specialite,
            ]);

        return response()->json($projets);
    }

    public function etudiantsDuChef(): JsonResponse
    {
        $chef = Auth::user();

        $etudiants = Utilisateur::where('role', 'etudiant')
            ->where('specialite_id', $chef->specialite_id)
            ->get();

        $result = $etudiants->map(function ($etudiant) {
            $projetPfe = ProjetPfe::with(['jury.membres.enseignant', 'encadrant'])
                ->where('etudiant_id', $etudiant->id)
                ->first();

            $jury = $projetPfe?->jury;

            return [
                'etudiant_id'   => $etudiant->id,
                'etudiant_nom'  => trim(($etudiant->prenom ?? '') . ' ' . ($etudiant->nom ?? '')),
                'matricule'     => $etudiant->matricule ?? '--',
                'encadrant_nom' => $projetPfe?->encadrant
                    ? trim(($projetPfe->encadrant->prenom ?? '') . ' ' . ($projetPfe->encadrant->nom ?? ''))
                    : '--',
                'encadrant_id'  => $projetPfe?->encadrant_id,
                'projet_pfe_id' => $projetPfe?->id,
                'projet_titre'  => $projetPfe?->titre,
                'jury_id'       => $jury?->id,
                'jury_statut'   => $jury?->statut,
                'nb_fiches'     => $jury?->notes()->where('finalise', true)->count() ?? 0,
                'membres'       => $jury ? $jury->membres->map(fn($m) => [
                    'id'            => $m->id,
                    'enseignant_id' => $m->enseignant_id,
                    'nom'           => trim(($m->enseignant->prenom ?? '') . ' ' . ($m->enseignant->nom ?? '')),
                    'fonction'      => $m->fonction,
                ]) : [],
            ];
        });

        return response()->json($result);
    }

    // ── Plans de soutenance ────────────────────────────────────────

    public function indexPlans(): JsonResponse
    {
        // Nouvelle structure : chaque ligne plans_soutenance = un créneau proposé
        // avec soutenance_id optionnel (FK → soutenances) + date/heure_debut/salle directement
        $plans = \App\Models\PlanSoutenance::with(['proposant', 'soutenance.projet'])
            ->latest()
            ->get()
            ->map(fn($p) => [
                'id'             => $p->id,
                'proposant_id'   => $p->proposant_id,
                'proposant_nom'  => $p->proposant
                    ? trim($p->proposant->prenom . ' ' . $p->proposant->nom)
                    : 'Inconnu',
                'role'           => $p->role,
                'statut'         => $p->statut,
                'soutenance_id'  => $p->soutenance_id,
                'date'           => $p->date,
                'heure_debut'    => $p->heure_debut,
                'salle'          => $p->salle,
                'projet_titre'   => $p->soutenance?->projet?->titre ?? null,
                'created_at'     => $p->created_at,
            ]);

        return response()->json($plans);
    }

    public function storePlan(Request $request): JsonResponse
    {
        // Nouvelle structure : une ligne = un créneau proposé (date/heure/salle directement)
        // soutenance_id est optionnel : le proposant peut lier son plan à une soutenance existante
        $data = $request->validate([
            'proposant_id'   => 'required|exists:utilisateurs,id',
            'role'           => 'required|in:jury,encadrant',
            'date'           => 'required|date',
            'heure_debut'    => 'required|date_format:H:i',
            'salle'          => 'required|string|max:100',
            'soutenance_id'  => 'nullable|exists:soutenances,id',
        ]);

        $plan = \App\Models\PlanSoutenance::create([
            'proposant_id'  => $data['proposant_id'],
            'role'          => $data['role'],
            'statut'        => 'en_attente',
            'soutenance_id' => $data['soutenance_id'] ?? null,
            'date'          => $data['date'],
            'heure_debut'   => $data['heure_debut'],
            'salle'         => $data['salle'],
        ]);

        $chefs = Utilisateur::whereIn('role', ['chef_departement', 'chef'])->get();
        foreach ($chefs as $chef) {
            Notification::create([
                'user_id' => $chef->id,
                'message' => "Un nouveau plan de soutenance a été proposé par un {$data['role']}.",
            ]);
        }

        return response()->json($plan->load('soutenance.projet'), 201);
    }

    public function validerPlan(\App\Models\PlanSoutenance $plan): JsonResponse
    {
        $plan->update(['statut' => 'approuve']);

        // Si le plan est lié à une soutenance et que celle-ci n'a pas encore de créneau,
        // on applique automatiquement la date/heure/salle proposées.
        if ($plan->soutenance_id && $plan->soutenance) {
            $soutenance = $plan->soutenance;
            if (!$soutenance->date_soutenance) {
                $soutenance->update([
                    'date_soutenance' => $plan->date,
                    'heure_debut'     => $plan->heure_debut,
                    'salle'           => $plan->salle,
                    'statut'          => 'planifie',
                ]);
            }
        }

        Notification::create([
            'user_id' => $plan->proposant_id,
            'message' => 'Votre plan de soutenance a été validé par le chef de département.',
        ]);
        return response()->json(['message' => 'Plan validé.']);
    }

    public function rejeterPlan(\App\Models\PlanSoutenance $plan): JsonResponse
    {
        $plan->update(['statut' => 'rejete']);
        Notification::create([
            'user_id' => $plan->proposant_id,
            'message' => 'Votre plan de soutenance a été rejeté par le chef de département.',
        ]);
        return response()->json(['message' => 'Plan rejeté.']);
    }


    // ── Mes notes (member view) ───────────────────────────────────

    /**
     * GET /api/jurys-pfe/mes-notes
     * Returns all NotePfe rows for the authenticated enseignant.
     */
    public function mesNotes(): JsonResponse
    {
        $userId = Auth::id();

        $notes = NotePfe::where('enseignant_id', $userId)
            ->with('jury.projet.etudiant')
            ->latest()
            ->get()
            ->map(fn($n) => [
                'id'          => $n->id,
                'jury_id'     => $n->jury_id,
                'note'        => $n->note,
                'commentaire' => $n->commentaire,
                'finalise'    => $n->finalise,
                'updated_at'  => $n->updated_at,
            ]);

        return response()->json($notes);
    }

    /**
     * GET /api/jurys-pfe/{jury}/ma-note
     * Returns the authenticated enseignant's note for this jury,
     * including per-critère breakdown.
     */
    public function maNoteDetail(Soutenance $juryPfe): JsonResponse
    {
        $userId = Auth::id();

        $note = NotePfe::where('jury_id', $juryPfe->id)
            ->where('enseignant_id', $userId)
            ->first();

        if (!$note) {
            return response()->json(null);
        }

        $criteres = DB::table('notes_grille_pfe')
            ->where('note_pfe_id', $note->id)
            ->get()
            ->map(fn($r) => [
                'critere_id' => $r->critere_id,
                'note'       => $r->note,
            ]);

        return response()->json([
            'id'          => $note->id,
            'jury_id'     => $note->jury_id,
            'note'        => $note->note,
            'commentaire' => $note->commentaire,
            'finalise'    => $note->finalise,
            'criteres'    => $criteres,
        ]);
    }

    // ── Private helpers ────────────────────────────────────────────

    private function format(Soutenance $j): array
    {
        $president = $j->membres?->firstWhere('fonction', 'president');

        return [
            'id'                => $j->id,
            'projet_id'         => $j->projet_id,
            'etudiant_id'       => $j->projet?->etudiant_id,
            'projet_titre'      => $j->projet?->titre ?? 'Sans titre',
            'etudiant_nom'      => trim((optional($j->projet?->etudiant)->prenom ?? '') . ' ' . (optional($j->projet?->etudiant)->nom ?? '')),
            'encadrant_nom'     => trim((optional($j->projet?->encadrant)->prenom ?? '') . ' ' . (optional($j->projet?->encadrant)->nom ?? '')),
            'date_soutenance'   => $j->date_soutenance,
            'heure_debut'       => $j->heure_debut,
            'heure_fin'         => $j->heure_fin,
            'salle'             => $j->salle,
            'statut'            => $j->statut,
            'calendrier_publie' => $j->calendrier_publie,
            'nb_membres'        => $j->membres?->count() ?? 0,
            'membres_complet'   => ($j->membres?->count() ?? 0) >= 3,
            'nb_fiches'         => $j->notes?->where('finalise', true)->count() ?? 0,
            'president'         => $president ? trim(($president->enseignant->prenom ?? '') . ' ' . ($president->enseignant->nom ?? '')) : null,
            'president_id'      => $president?->enseignant_id,
            'membres'           => $j->membres?->map(fn($m) => [
                'id'            => $m->id,
                'enseignant_id' => $m->enseignant_id,
                'nom'           => trim((optional($m->enseignant)->prenom ?? '') . ' ' . (optional($m->enseignant)->nom ?? '')),
                'email'         => optional($m->enseignant)->email,
                'fonction'      => $m->fonction,
            ]),
            'resultat' => $j->resultat ? [
                'note_finale' => $j->resultat->note_finale,
                'mention'     => $j->resultat->mention,
                'decision'    => $j->resultat->decision,
                'publie'      => $j->resultat->publie,
                'en_biblio'   => $j->resultat->en_biblio ?? false,
                'archive'     => $j->resultat->archive ?? false,
            ] : null,
        ];
    }

    private function getMention(float $note): string
    {
        return match(true) {
            $note >= 16 => 'Très bien',
            $note >= 14 => 'Bien',
            $note >= 12 => 'Assez bien',
            $note >= 10 => 'Passable',
            default     => 'Insuffisant',
        };
    }
}