<?php

namespace App\Http\Controllers;

use App\Models\JuryMembrePfe;
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
 *   GET  /api/jurys-pfe/mes-notes                          → mesNotes
 *   GET  /api/jurys-pfe/prets-a-deliberer                  → pretsADeliberer
 *   GET  /api/fiches-evaluation                            → fichesEvaluation
 *   GET  /api/jurys-pfe/{juryPfe}/notes                    → getNotes
 *   POST /api/jurys-pfe/{juryPfe}/notes                    → saveNote
 *   GET  /api/jurys-pfe/{juryPfe}/ma-note                  → maNoteDetail
 *   GET  /api/jurys-pfe/{juryPfe}/evaluation-recue         → evaluationRecue
 *   POST /api/jurys-pfe/{juryPfe}/deliberer                → deliberer
 *   POST /api/jurys-pfe/{juryPfe}/publier                  → publier (single result via jury)
 *   GET  /api/deliberation-pfe/mon-resultat                → monResultat
 *
 * NOTE: POST /api/resultats-pfe/publier-tous is now handled exclusively
 *       by ResultatPfeController::publierTous() (with notifications).
 *
 * Evaluation access rules
 * ───────────────────────
 *  • Only the **président** of a jury may submit/update an evaluation.
 *  • Encadrant / examinateur members may only **consult** (read).
 *  • A soutenance must be in statut = 'publie' AND its scheduled
 *    date+time (date_soutenance + heure_debut) must have already passed
 *    before any evaluation can be saved.
 *
 * Deliberation decision logic
 * ────────────────────────────
 *  • The automatic decision is computed from note_finale: ≥10 → admis, <10 → ajourne.
 *  • The chef may override this via an optional `decision` field in the POST body,
 *    allowing deliberation with Ajourné even when note ≥ 10 (disciplinary, etc.).
 *  • Once published, the decision is locked and cannot be changed.
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
     * GET /api/jurys-pfe/{juryPfe}/evaluation-recue
     *
     * Returns the président's submitted evaluation to an encadrant or examinateur
     * who belongs to the same jury, respecting the grille visibility setting.
     *
     * Visibility (grille.visibilite):
     *   'encadrant_jury' → full grille (categories + critères + commentaire + note)
     *   'jury_only'      → note finale only (no grille breakdown, no commentaire)
     *
     * Returns 403 if the authenticated user is not encadrant/examinateur of this jury.
     * Returns null (200) if the président has not yet submitted an evaluation.
     */
    public function evaluationRecue(Soutenance $juryPfe): JsonResponse
    {
        $userId = Auth::id();

        // ── 1. Resolve the caller's role in this jury ──────────────────────
        $juryPfe->loadMissing(['projet.etudiant']);

        $juryComposition = JuryMembrePfe::where('projet_id', $juryPfe->projet_id)->first();

        if (!$juryComposition) {
            return response()->json(['message' => 'Composition de jury introuvable.'], 403);
        }

        $fonction = match (true) {
            (int) $juryComposition->encadrant_id   === (int) $userId => 'encadrant',
            (int) $juryComposition->examinateur_id === (int) $userId => 'examinateur',
            (int) $juryComposition->president_id   === (int) $userId => 'president',
            default                                                    => null,
        };

        if (!$fonction || $fonction === 'president') {
            return response()->json(['message' => 'Accès réservé aux membres encadrant/examinateur.'], 403);
        }

        // ── 2. Find the président's finalised note ─────────────────────────
        $presidentId = $juryComposition->president_id;

        $notePresident = NotePfe::where('soutenance_id', $juryPfe->id)
            ->where('enseignant_id', $presidentId)
            ->finalise()
            ->first();

        if (!$notePresident) {
            return response()->json(null);
        }

        // ── 3. Determine visibility from the active grille ─────────────────
        $specialiteId = $juryPfe->projet?->etudiant?->specialite_id
            ?? $juryPfe->projet?->specialite_id
            ?? null;

        $visibilite = $this->resolveGrilleVisibilite($specialiteId);

        $noteVal = $notePresident->note !== null ? (float) $notePresident->note : null;

        // ── 4. Build response according to visibility ──────────────────────
        if ($visibilite === 'encadrant_jury') {
            $categories = $this->loadCategories($notePresident->id);

            return response()->json([
                'visibilite'  => 'encadrant_jury',
                'note'        => $noteVal,
                'commentaire' => $notePresident->commentaire,
                'finalise'    => (bool) $notePresident->finalise,
                'soumis_le'   => $notePresident->updated_at?->format('d/m/Y H:i'),
                'categories'  => $categories,
            ]);
        }

        return response()->json([
            'visibilite'  => 'jury_only',
            'note'        => $noteVal,
            'commentaire' => null,
            'finalise'    => (bool) $notePresident->finalise,
            'soumis_le'   => $notePresident->updated_at?->format('d/m/Y H:i'),
            'categories'  => [],
        ]);
    }

    /**
     * POST /api/jurys-pfe/{juryPfe}/notes
     *
     * Submit or update a note (président only).
     * Optionally includes per-critère detail rows.
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

        $juryComposition = JuryMembrePfe::where('projet_id', $juryPfe->projet_id)->first();

        if (!$juryComposition) {
            return response()->json(['message' => "Aucune composition de jury trouvée pour cette soutenance."], 403);
        }

        $enseignantId = $data['enseignant_id'];
        $fonction = match (true) {
            $juryComposition->president_id   === $enseignantId => 'president',
            $juryComposition->encadrant_id   === $enseignantId => 'encadrant',
            $juryComposition->examinateur_id === $enseignantId => 'examinateur',
            default                                             => null,
        };

        if (!$fonction) {
            return response()->json(['message' => "Cet enseignant n'est pas membre de ce jury."], 403);
        }

        if ($fonction !== 'president') {
            return response()->json([
                'message' => "Seul le président du jury peut soumettre une évaluation.",
            ], 403);
        }

        if ($juryPfe->statut !== 'publie') {
            return response()->json([
                'message' => "Cette soutenance n'est pas encore publiée et ne peut pas encore être évaluée.",
            ], 422);
        }

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

            $this->notifierEvaluation($juryPfe, $note->wasRecentlyCreated);

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
     * All jury compositions in the chef's department with per-member notes.
     */
    public function fichesEvaluation(): JsonResponse
    {
        $chef = Auth::user();

        if (!$chef || !$chef->specialite_id) {
            return response()->json([]);
        }

        $compositions = JuryMembrePfe::with([
            'projet.etudiant',
            'encadrant',
            'president',
            'examinateur',
            'soutenance.notes.enseignant',
            'soutenance.resultat',
        ])
        ->where('publie', true)
        ->whereHas('projet.etudiant', fn($q) =>
            $q->where('specialite_id', $chef->specialite_id)
        )
        ->get();

        $result = $compositions->map(function (JuryMembrePfe $jury) {
            $soutenance = $jury->soutenance;
            $notes      = $soutenance?->notes ?? collect();

            $fiches = $notes->map(function ($note) use ($jury) {
                $uid      = (int) $note->enseignant_id;
                $fonction = match (true) {
                    $uid === (int) $jury->president_id   => 'president',
                    $uid === (int) $jury->encadrant_id   => 'encadrant',
                    $uid === (int) $jury->examinateur_id => 'examinateur',
                    default                              => 'inconnu',
                };

                $ens        = $note->enseignant;
                $categories = $this->loadCategories($note->id);

                return [
                    'id'              => $note->id,
                    'membre_id'       => $note->enseignant_id,
                    'membre_nom'      => $ens ? trim($ens->prenom . ' ' . $ens->nom) : '--',
                    'fonction'        => $fonction,
                    'note_totale'     => $note->note,
                    'commentaire'     => $note->commentaire,
                    'finalise'        => (bool) $note->finalise,
                    'date_soumission' => $note->updated_at?->format('d/m/Y H:i'),
                    'categories'      => $categories,
                ];
            })->values();

            $resultat = $soutenance?->resultat;

            return [
                'jury_id'         => $jury->id,
                'soutenance_id'   => $soutenance?->id,
                'etudiant_nom'    => trim(($jury->projet?->etudiant?->prenom ?? '') . ' ' . ($jury->projet?->etudiant?->nom ?? '')),
                'matricule'       => $jury->projet?->etudiant?->matricule ?? '--',
                'projet_titre'    => $jury->projet?->titre ?? 'Sans titre',
                'encadrant_nom'   => $jury->encadrant
                    ? trim(($jury->encadrant->prenom ?? '') . ' ' . ($jury->encadrant->nom ?? ''))
                    : '--',
                'president_nom'   => $jury->president
                    ? trim(($jury->president->prenom ?? '') . ' ' . ($jury->president->nom ?? ''))
                    : null,
                'president_id'    => $jury->president_id,
                'date_soutenance' => $soutenance?->date_soutenance,
                'heure_debut'     => $soutenance?->heure_debut ? substr($soutenance->heure_debut, 0, 5) : null,
                'salle'           => $soutenance?->salle,
                'resultat'        => $resultat ? [
                    'note_finale' => $resultat->note_finale,
                    'mention'     => $resultat->mention,
                    'decision'    => $resultat->decision,
                    'publie'      => (bool) $resultat->publie,
                ] : null,
                'fiches'          => $fiches,
            ];
        });

        return response()->json($result);
    }

    // ── Délibération ──────────────────────────────────────────────

    /**
     * GET /api/jurys-pfe/prets-a-deliberer
     *
     * Jurys whose président has submitted a finalised note but have no ResultatPfe yet.
     * Returned to ConsulterResultatFinal.vue to populate the pending deliberation section.
     */
    public function pretsADeliberer(): JsonResponse
    {
        $chef = Auth::user();

        if (!$chef || !$chef->specialite_id) {
            return response()->json([]);
        }

        $compositions = JuryMembrePfe::with(['projet.etudiant', 'soutenance'])
            ->where('publie', true)
            ->whereNotNull('president_id')
            ->whereHas('projet.etudiant', fn($q) =>
                $q->where('specialite_id', $chef->specialite_id)
            )
            ->get();

        $result = [];

        foreach ($compositions as $jury) {
            $soutenance = $jury->soutenance;
            if (!$soutenance) continue;

            // Must not have a ResultatPfe yet
            $hasResultat = DB::table('resultats_pfe')
                ->where('soutenance_id', $soutenance->id)
                ->exists();
            if ($hasResultat) continue;

            // Soutenance must be published or finalised
            if (!in_array($soutenance->statut, ['publie', 'finalise', 'termine'])) continue;

            // President must have a finalised note in notes_pfe
            $notePresident = DB::table('notes_pfe')
                ->where('soutenance_id', $soutenance->id)
                ->where('enseignant_id', $jury->president_id)
                ->where('finalise', true)
                ->first();

            if (!$notePresident) continue;

            $etudiant = $jury->projet?->etudiant;

            $result[] = [
                'jury_id'        => $soutenance->id,
                'jury_membre_id' => $jury->id,
                'etudiant_nom'   => trim(($etudiant?->prenom ?? '') . ' ' . ($etudiant?->nom ?? '')),
                'matricule'      => $etudiant?->matricule ?? '--',
                'projet_titre'   => $jury->projet?->titre ?? 'Sans titre',
                'note_president' => (float) $notePresident->note,
            ];
        }

        return response()->json(array_values($result));
    }

    /**
     * POST /api/jurys-pfe/{juryPfe}/deliberer
     *
     * Computes note finale from the président's finalised note and stores a ResultatPfe.
     *
     * Optional body field:
     *   decision (string) — 'admis' | 'ajourne'
     *     If provided, overrides the automatic threshold decision (note >= 10 → admis).
     *     This allows the chef to manually mark a passing student as Ajourné
     *     (e.g. missing documents, disciplinary reasons) directly from the
     *     deliberation panel in ConsulterResultatFinal.vue.
     *     If not provided, the decision is computed automatically from the note.
     */
    public function deliberer(Request $request, Soutenance $juryPfe): JsonResponse
    {
        $juryComposition = JuryMembrePfe::where('projet_id', $juryPfe->projet_id)->first();
        $presidentId     = $juryComposition?->president_id;

        if (!$presidentId) {
            return response()->json(['message' => 'Aucun président désigné pour ce jury.'], 422);
        }

        $notePresident = $juryPfe->notes()
            ->where('enseignant_id', $presidentId)
            ->finalise()
            ->first();

        if (!$notePresident) {
            return response()->json([
                'message' => "Le président n'a pas encore soumis sa fiche d'évaluation.",
            ], 422);
        }

        $noteFinale = round((float) $notePresident->note, 2);

        // ── Decision resolution ────────────────────────────────────────
        $decisionInput = $request->input('decision');
        $decision = in_array($decisionInput, ['admis', 'ajourne'])
            ? $decisionInput
            : ($noteFinale >= 10 ? 'admis' : 'ajourne');

        $resultat = ResultatPfe::updateOrCreate(
            ['soutenance_id' => $juryPfe->id],
            [
                'etudiant_id' => $juryPfe->projet->etudiant_id,
                'note_finale' => $noteFinale,
                'mention'     => $this->getMention($noteFinale),
                'decision'    => $decision,
                'publie'      => false,
            ]
        );

        $juryPfe->update(['statut' => 'termine']);

        return response()->json($resultat);
    }

    // ── Publication des résultats ─────────────────────────────────

    /**
     * POST /api/jurys-pfe/{juryPfe}/publier
     * Publishes a single result via the jury route and notifies student + encadrant.
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

    private function resolveGrilleVisibilite(?int $specialiteId): string
    {
        try {
            $query = DB::table('grilles_evaluation')
                ->whereIn('statut', ['verrouille', 'publie']);

            if ($specialiteId) {
                $scoped = (clone $query)->where('specialite_id', $specialiteId)->get(['statut', 'visibilite']);
                $grille = $scoped->firstWhere('statut', 'verrouille') ?? $scoped->firstWhere('statut', 'publie');
                if ($grille) return $grille->visibilite ?? 'encadrant_jury';
            }

            $all    = $query->get(['statut', 'visibilite']);
            $grille = $all->firstWhere('statut', 'verrouille') ?? $all->firstWhere('statut', 'publie');
            return $grille?->visibilite ?? 'encadrant_jury';
        } catch (\Throwable $e) {
            Log::warning('resolveGrilleVisibilite error: ' . $e->getMessage());
            return 'jury_only';
        }
    }

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
                ? substr((string) $soutenance->heure_fin, 0, 5)
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

    private function notifierEvaluation(Soutenance $juryPfe, bool $isNew): void
    {
        try {
            $juryPfe->loadMissing([
                'projet.etudiant',
                'projet.encadrant',
                'membres.enseignant',
            ]);

            $projet    = $juryPfe->projet;
            $etudiant  = $projet?->etudiant;
            $encadrant = $projet?->encadrant;

            if (!$etudiant) return;

            $etudiantNom  = trim(($etudiant->prenom ?? '') . ' ' . ($etudiant->nom ?? ''));
            $projetTitre  = $projet->titre ?? 'Projet sans titre';
            $action       = $isNew ? 'soumis' : 'mis à jour';
            $actionLabel  = $isNew ? 'soumise' : 'mise à jour';

            if ($encadrant) {
                Notification::create([
                    'user_id' => $encadrant->id,
                    'message' => "La fiche d'évaluation de votre étudiant(e) {$etudiantNom} "
                               . "(projet : « {$projetTitre} ») a été {$actionLabel} par le président du jury.",
                ]);
            }

            $examinateurs = $juryPfe->membres
                ->where('fonction', 'examinateur')
                ->filter(fn($m) => $m->enseignant !== null);

            foreach ($examinateurs as $membre) {
                Notification::create([
                    'user_id' => $membre->enseignant->id,
                    'message' => "La fiche d'évaluation du projet « {$projetTitre} » "
                               . "(étudiant(e) : {$etudiantNom}) a été {$actionLabel} par le président du jury.",
                ]);
            }

            if ($etudiant->specialite_id) {
                $chefs = \App\Models\Utilisateur::where('role', 'chef_departement')
                    ->where('specialite_id', $etudiant->specialite_id)
                    ->get();

                $presidentMembre = $juryPfe->membres->firstWhere('fonction', 'president');
                $presidentEns    = $presidentMembre?->enseignant;
                $presidentNom    = $presidentEns
                    ? trim(($presidentEns->prenom ?? '') . ' ' . ($presidentEns->nom ?? ''))
                    : 'Le président du jury';

                foreach ($chefs as $chef) {
                    Notification::create([
                        'user_id' => $chef->id,
                        'message' => "{$presidentNom} a {$action} la fiche d'évaluation du jury "
                                   . "pour le projet « {$projetTitre} » (étudiant(e) : {$etudiantNom}).",
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('notifierEvaluation: ' . $e->getMessage());
        }
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