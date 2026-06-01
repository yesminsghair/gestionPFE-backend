<?php

namespace App\Http\Controllers;

use App\Models\NotePfe;
use App\Models\Notification;
use App\Models\ResultatPfe;
use App\Models\Soutenance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * EvaluationPfeController
 *
 * Handles jury evaluation: note submission, deliberation, result publication,
 * fiches d'évaluation, and student/encadrant result views.
 *
 * Routes:
 *   GET  /api/jurys-pfe/mes-notes                  → mesNotes
 *   GET  /api/jurys-pfe/prets-a-deliberer           → pretsADeliberer
 *   GET  /api/fiches-evaluation                     → fichesEvaluation
 *   GET  /api/jurys-pfe/{juryPfe}/notes             → getNotes
 *   POST /api/jurys-pfe/{juryPfe}/notes             → saveNote
 *   GET  /api/jurys-pfe/{juryPfe}/ma-note           → maNoteDetail
 *   POST /api/jurys-pfe/{juryPfe}/deliberer         → deliberer
 *   POST /api/jurys-pfe/{juryPfe}/publier           → publier (single result)
 *   POST /api/resultats-pfe/publier-tous            → publierTous
 *   GET  /api/deliberation-pfe/mon-resultat         → monResultat
 *
 * Evaluation access rules
 * ───────────────────────
 *  • Only the **président** of a jury may submit/update an evaluation.
 *  • Encadrant / examinateur members may only **consult** (read).
 *  • A soutenance must be in statut = 'publie' AND its scheduled
 *    date+time (date_soutenance + heure_debut) must have already passed
 *    before any evaluation can be saved.
 */
class EvaluationPfeController extends Controller
{
    // ── Notes ─────────────────────────────────────────────────────

    /**
     * GET /api/jurys-pfe/mes-notes
     * All NotePfe rows for the authenticated enseignant.
     */
    public function mesNotes(): JsonResponse
    {
        $userId = Auth::id();

        $notes = NotePfe::where('enseignant_id', $userId)
            ->with('soutenance.projet.etudiant')
            ->latest()
            ->get()
            ->map(fn($n) => [
                'id'          => $n->id,
                'jury_id'     => $n->soutenance_id,
                'note'        => $n->note,
                'commentaire' => $n->commentaire,
                'finalise'    => $n->finalise,
                'updated_at'  => $n->updated_at,
            ]);

        return response()->json($notes);
    }

    /**
     * GET /api/jurys-pfe/{juryPfe}/notes
     * All notes for a given jury with per-critère breakdown (chef/admin view).
     */
    public function getNotes(Soutenance $juryPfe): JsonResponse
    {
        $juryPfe->loadMissing('membres', 'membres.enseignant');
        $notes = $juryPfe->notes()->with('enseignant')->get();

        $result = $notes->map(function ($note) use ($juryPfe) {
            $categories = $this->loadCategories($note->id);
            $membre     = $juryPfe->membres->firstWhere('enseignant_id', $note->enseignant_id);

            return [
                'id'            => $note->id,
                'enseignant_id' => $note->enseignant_id,
                'membre_nom'    => $note->enseignant
                    ? trim($note->enseignant->prenom . ' ' . $note->enseignant->nom)
                    : '--',
                'fonction'      => $membre?->fonction,
                'note'          => $note->note,
                'commentaire'   => $note->commentaire,
                'finalise'      => $note->finalise,
                'categories'    => $categories,
            ];
        });

        return response()->json($result);
    }

    /**
     * GET /api/jurys-pfe/{juryPfe}/ma-note
     * The authenticated enseignant's own note for this jury (with per-critère breakdown).
     */
    public function maNoteDetail(Soutenance $juryPfe): JsonResponse
    {
        $userId = Auth::id();

        $note = NotePfe::where('soutenance_id', $juryPfe->id)
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
            'jury_id'     => $note->soutenance_id,
            'note'        => $note->note,
            'commentaire' => $note->commentaire,
            'finalise'    => $note->finalise,
            'criteres'    => $criteres,
        ]);
    }

    /**
     * POST /api/jurys-pfe/{juryPfe}/notes
     *
     * Submit or update a note (président only).
     * Optionally includes per-critère detail rows.
     *
     * Guards:
     *  1. The requesting enseignant must be the **président** of the jury.
     *  2. The soutenance statut must be 'publie'.
     *  3. The scheduled date+time (date_soutenance + heure_debut) must be
     *     in the past (i.e. the defence session has started / elapsed).
     */
    public function saveNote(Request $request, Soutenance $juryPfe): JsonResponse
    {
        $data = $request->validate([
            'enseignant_id'         => 'required|exists:utilisateurs,id',
            'note'                  => 'required|numeric|min:0|max:20',
            'commentaire'           => 'nullable|string',
            'finalise'              => 'boolean',
            'criteres'              => 'nullable|array',
            'criteres.*.critere_id' => 'required|integer',
            'criteres.*.note'       => 'required|numeric|min:0',
        ]);

        // ── Guard 1 : must be membre of this jury ──────────────────
        $membre = $juryPfe->membres()
            ->where('enseignant_id', $data['enseignant_id'])
            ->first();

        if (!$membre) {
            return response()->json(['message' => "Cet enseignant n'est pas membre de ce jury."], 403);
        }

        // ── Guard 2 : only the président may evaluate ──────────────
        if ($membre->fonction !== 'president') {
            return response()->json([
                'message' => "Seul le président du jury peut soumettre une évaluation.",
            ], 403);
        }

        // ── Guard 3 : soutenance must be published ─────────────────
        if ($juryPfe->statut !== 'publie') {
            return response()->json([
                'message' => "Cette soutenance n'est pas encore publiée et ne peut pas encore être évaluée.",
            ], 422);
        }

        // ── Guard 4 : soutenance must have ended (heure_fin passed) ──
        if (!$this->soutenanceHasEnded($juryPfe)) {
            return response()->json([
                'message' => "L'évaluation ne peut être soumise qu'après la fin de la soutenance.",
            ], 422);
        }

        DB::beginTransaction();
        try {
            $note = NotePfe::updateOrCreate(
                ['soutenance_id' => $juryPfe->id, 'enseignant_id' => $data['enseignant_id']],
                [
                    'note'        => $data['note'],
                    'commentaire' => $data['commentaire'] ?? null,
                    'finalise'    => $data['finalise'] ?? false,
                ]
            );

            if (!empty($data['criteres'])) {
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
            return response()->json(['message' => "Erreur lors de l'enregistrement."], 500);
        }
    }

    // ── Fiches d'évaluation ───────────────────────────────────────

    /**
     * GET /api/fiches-evaluation
     * All jurys in the chef's department with per-member notes and per-critère breakdown.
     */
    public function fichesEvaluation(): JsonResponse
    {
        $chef = Auth::user();

        if (!$chef || !$chef->specialite_id) {
            return response()->json([]);
        }

        $jurys = Soutenance::with([
            'projet.etudiant',
            'projet.encadrant',
            'membres.enseignant',
            'notes',
            'resultat',
        ])
        ->whereHas('projet.etudiant', fn($q) => $q->where('specialite_id', $chef->specialite_id))
        ->get();

        $result = $jurys->map(function ($jury) {
            $presidentMembre = $jury->membres->firstWhere('fonction', 'president');
            $presidentEns    = $presidentMembre?->enseignant;

            $fiches = $jury->notes->map(function ($note) use ($jury) {
                $membre     = $jury->membres->firstWhere('enseignant_id', $note->enseignant_id);
                $ens        = $membre?->enseignant ?? $note->enseignant;
                $categories = $this->loadCategories($note->id);

                return [
                    'id'              => $note->id,
                    'membre_id'       => $note->enseignant_id,
                    'membre_nom'      => $ens ? trim($ens->prenom . ' ' . $ens->nom) : '--',
                    'fonction'        => $membre?->fonction,
                    'note_totale'     => $note->note,
                    'commentaire'     => $note->commentaire,
                    'finalise'        => (bool) $note->finalise,
                    'date_soumission' => $note->updated_at?->format('d/m/Y H:i'),
                    'categories'      => $categories,
                ];
            })->values();

            return [
                'jury_id'         => $jury->id,
                'etudiant_nom'    => trim(($jury->projet?->etudiant?->prenom ?? '') . ' ' . ($jury->projet?->etudiant?->nom ?? '')),
                'matricule'       => $jury->projet?->etudiant?->matricule ?? '--',
                'projet_titre'    => $jury->projet?->titre ?? 'Sans titre',
                'encadrant_nom'   => $jury->projet?->encadrant
                    ? trim(($jury->projet->encadrant->prenom ?? '') . ' ' . ($jury->projet->encadrant->nom ?? ''))
                    : '--',
                'president_nom'   => $presidentEns
                    ? trim($presidentEns->prenom . ' ' . $presidentEns->nom)
                    : null,
                'president_id'    => $presidentMembre?->enseignant_id,
                'date_soutenance' => $jury->date_soutenance,
                'heure_debut'     => $jury->heure_debut ? substr($jury->heure_debut, 0, 5) : null,
                'salle'           => $jury->salle,
                'resultat'        => $jury->resultat ? [
                    'note_finale' => $jury->resultat->note_finale,
                    'mention'     => $jury->resultat->mention,
                    'decision'    => $jury->resultat->decision,
                    'publie'      => (bool) $jury->resultat->publie,
                ] : null,
                'fiches'          => $fiches,
            ];
        });

        return response()->json($result);
    }

    // ── Délibération ──────────────────────────────────────────────

    /**
     * GET /api/jurys-pfe/prets-a-deliberer
     * Jurys whose président has submitted a finalised note but have no ResultatPfe yet.
     */
    public function pretsADeliberer(): JsonResponse
    {
        $chef = Auth::user();

        if (!$chef || !$chef->specialite_id) {
            return response()->json([]);
        }

        $jurys = Soutenance::with(['projet.etudiant', 'notes', 'membres'])
            ->whereHas('projet.etudiant', fn($q) => $q->where('specialite_id', $chef->specialite_id))
            ->doesntHave('resultat')
            ->get()
            ->filter(function ($jury) {
                $presidentId = $jury->membres->firstWhere('fonction', 'president')?->enseignant_id;
                if (!$presidentId) return false;

                return $jury->notes
                    ->where('enseignant_id', $presidentId)
                    ->where('finalise', true)
                    ->isNotEmpty();
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

    /**
     * POST /api/jurys-pfe/{juryPfe}/deliberer
     * Computes note finale from the président's finalised note and stores a ResultatPfe.
     */
    public function deliberer(Soutenance $juryPfe): JsonResponse
    {
        $juryPfe->load('membres');

        $presidentId = $juryPfe->membres->firstWhere('fonction', 'president')?->enseignant_id;
        if (!$presidentId) {
            return response()->json(['message' => 'Aucun président désigné pour ce jury.'], 422);
        }

        $notePresident = $juryPfe->notes()
            ->where('enseignant_id', $presidentId)
            ->where('finalise', true)
            ->first();

        if (!$notePresident) {
            return response()->json([
                'message' => "Le président n'a pas encore soumis sa fiche d'évaluation.",
            ], 422);
        }

        $noteFinale = round((float) $notePresident->note, 2);

        $resultat = ResultatPfe::updateOrCreate(
            ['soutenance_id' => $juryPfe->id],
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

    // ── Publication des résultats ─────────────────────────────────

    /**
     * POST /api/jurys-pfe/{juryPfe}/publier
     * Publishes a single result and notifies the student + encadrant.
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

        Notification::create([
            'user_id' => $etudiantId,
            'message' => 'Vos résultats de soutenance PFE sont disponibles. Connectez-vous pour les consulter.',
        ]);

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
     * Publishes ALL unpublished results in the chef's department.
     */
    public function publierTous(): JsonResponse
    {
        $chef = Auth::user();

        if (!$chef || !$chef->specialite_id) {
            return response()->json(['message' => '0 résultat(s) publiés.']);
        }

        $resultats = ResultatPfe::where('publie', false)
            ->whereHas('soutenance.projet.etudiant', fn($q) => $q->where('specialite_id', $chef->specialite_id))
            ->with('soutenance.projet.etudiant', 'soutenance.projet.encadrant')
            ->get();

        foreach ($resultats as $r) {
            $r->update(['publie' => true, 'publie_le' => now()]);

            $etudiantId  = $r->soutenance?->projet?->etudiant_id;
            $encadrantId = $r->soutenance?->projet?->encadrant_id;
            $etudiant    = $r->soutenance?->projet?->etudiant;
            $nom         = trim(($etudiant?->prenom ?? '') . ' ' . ($etudiant?->nom ?? ''));

            if ($etudiantId) {
                Notification::create([
                    'user_id' => $etudiantId,
                    'message' => 'Vos résultats de soutenance PFE sont disponibles. Connectez-vous pour les consulter.',
                ]);
            }
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
     * GET /api/deliberation-pfe/mon-resultat
     * The authenticated student's published result.
     */
    public function monResultat(Request $request): JsonResponse
    {
        $etudiantId = $request->user()->id;

        $resultat = ResultatPfe::whereHas('soutenance.projet', fn($q) =>
            $q->where('etudiant_id', $etudiantId)
        )->where('publie', true)->with('soutenance.projet')->first();

        if (!$resultat) return response()->json(null);

        return response()->json([
            'note_finale'  => $resultat->note_finale,
            'mention'      => $resultat->mention,
            'decision'     => $resultat->decision,
            'publie_le'    => $resultat->publie_le?->format('d/m/Y'),
            'projet_titre' => $resultat->soutenance?->projet?->titre ?? 'Sans titre',
        ]);
    }

    // ── Private helpers ───────────────────────────────────────────

    /**
     * Returns true if the soutenance has fully ended, i.e.
     * date_soutenance + heure_fin is in the past.
     *
     * Handles edge cases:
     *  • date_soutenance is null → cannot evaluate (returns false).
     *  • heure_fin is null       → fall back to heure_debut; if also null → false.
     */
    private function soutenanceHasEnded(Soutenance $soutenance): bool
    {
        if (empty($soutenance->date_soutenance)) {
            return false;
        }

        try {
            $dateStr = $soutenance->date_soutenance instanceof \Carbon\Carbon
                ? $soutenance->date_soutenance->format('Y-m-d')
                : (string) $soutenance->date_soutenance;

            $heureStr = $soutenance->heure_fin
                ? substr((string) $soutenance->heure_fin, 0, 5)    // "HH:MM"
                : ($soutenance->heure_debut
                    ? substr((string) $soutenance->heure_debut, 0, 5)
                    : null);

            if ($heureStr === null) {
                return false;
            }

            $endsAt = Carbon::createFromFormat('Y-m-d H:i', $dateStr . ' ' . $heureStr);

            return Carbon::now()->greaterThanOrEqualTo($endsAt);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Loads the per-critère breakdown for a given NotePfe id,
     * grouped by category.
     */
    private function loadCategories(int $notePfeId): \Illuminate\Support\Collection
    {
        $rows = DB::table('notes_grille_pfe as ngp')
            ->join('criteres_evaluation as ce', 'ce.id', '=', 'ngp.critere_id')
            ->join('categories_grille as cg',   'cg.id', '=', 'ce.categorie_id')
            ->where('ngp.note_pfe_id', $notePfeId)
            ->select(
                'ngp.note as note_critere',
                'ce.id as critere_id',
                'ce.nom as critere_nom',
                'ce.bareme_max as critere_bareme',
                'cg.id as cat_id',
                'cg.nom as cat_nom',
                'cg.bareme_max as cat_bareme'
            )
            ->get();

        return $rows->groupBy('cat_id')->map(function ($catRows, $catId) {
            $first = $catRows->first();
            return [
                'id'      => $catId,
                'nom'     => $first->cat_nom,
                'bareme'  => $first->cat_bareme,
                'note'    => round($catRows->sum('note_critere'), 2),
                'criteres' => $catRows->map(fn($r) => [
                    'id'     => $r->critere_id,
                    'nom'    => $r->critere_nom,
                    'bareme' => $r->critere_bareme,
                    'note'   => $r->note_critere,
                ])->values(),
            ];
        })->values();
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