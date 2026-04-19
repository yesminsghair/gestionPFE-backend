<?php

namespace App\Http\Controllers;

use App\Models\Affectation;
use App\Models\Notification;
use App\Models\Phase;
use App\Models\SuiviEtudiantPhase;
use App\Models\Utilisateur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SuiviController extends Controller
{
    /**
     * GET /api/suivi/encadrant
     *
     * Returns all affectations for the logged-in encadrant.
     * Phase statut is now driven by the `active` / `terminee` flags set by the chef,
     * with the suivi record providing the encadrant's own validation state on top.
     *
     * Priority:
     *   1. suivi record exists → use its statut (en_cours | validee | rejetee)
     *   2. phase.terminee=true → 'terminee' (phase closed by chef)
     *   3. phase.active=true   → 'en_cours'  (phase open but encadrant hasn't acted yet)
     *   4. otherwise           → 'en_attente'
     */
    public function parEncadrant(): JsonResponse
    {
        try {
            if (! Auth::check()) {
                return response()->json(['error' => 'Non authentifié'], 401);
            }

            $encadrantId = Auth::id();

            $affectations = Affectation::with([
                'etudiant',
                'suiviPhases.phase',
            ])->where('encadrant_id', $encadrantId)->get();

            // Load ALL phases belonging to the chef of the encadrant's speciality
            $encadrant = Utilisateur::find($encadrantId);
            $chef = Utilisateur::where('role', 'chef')
                ->where('specialite_id', $encadrant?->specialite_id)
                ->first();

            $phases = $chef
                ? Phase::where('chef_id', $chef->id)->orderBy('ordre')->get()
                : Phase::orderBy('ordre')->get();

            $data = $affectations->map(function ($aff) use ($phases) {

                $suiviMap = $aff->suiviPhases->keyBy('phase_id');

                $phasesData = $phases->map(function ($p) use ($suiviMap) {

                    $suivi = $suiviMap->get($p->id);

                    // Derive statut
                    if ($suivi) {
                        $statut = $suivi->statut;           // en_cours | validee | rejetee
                    } elseif ($p->terminee) {
                        $statut = 'terminee';
                    } elseif ($p->active) {
                        $statut = 'en_cours';
                    } else {
                        $statut = 'en_attente';
                    }

                    return [
                        'phase_id'              => $p->id,
                        'nom'                   => $p->nom,
                        'ordre'                 => $p->ordre,
                        'date_debut'            => $p->date_debut,
                        'date_fin'              => $p->date_fin,
                        'coefficient'           => $p->coefficient,
                        'livrable_obligatoire'  => $p->livrable_obligatoire,
                        'active'                => $p->active,
                        'terminee'              => $p->terminee,
                        'suivi_id'              => $suivi?->id,
                        'statut'                => $statut,
                        'date_lancement'        => $suivi?->date_lancement,
                        'date_validation'       => $suivi?->date_validation,
                        'commentaire_encadrant' => $suivi?->commentaire_encadrant,
                    ];
                });

                // Progression: count phases validated by the encadrant
                $validees = $phasesData->where('statut', 'validee')->count();
                $total    = $phases->count();
                $progress = $total ? round($validees / $total * 100) : 0;

                // Current phase = the one that is en_cours
                $enCours = $phasesData->firstWhere('statut', 'en_cours');

                return [
                    'id'           => $aff->id,
                    'etudiant_id'  => $aff->etudiant_id,
                    'nom'          => trim(
                        (optional($aff->etudiant)->nom    ?? '') . ' ' .
                        (optional($aff->etudiant)->prenom ?? '')
                    ),
                    'sujet'        => $aff->titre_projet,
                    'phases'       => $phasesData,
                    'progress'     => $progress,
                    'phaseActuelle'=> $enCours ? $enCours['nom'] : '—',
                    'phaseActive'  => (bool) $enCours,
                    'termineTotal' => $progress === 100,
                ];
            });

            return response()->json($data);

        } catch (\Throwable $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'line'  => $e->getLine(),
                'file'  => $e->getFile(),
            ], 500);
        }
    }

    /**
     * GET /api/suivi/etudiant
     *
     * Returns the logged-in student's phase progress.
     * Returns null (not 500) when no affectation exists yet.
     */
    public function parEtudiant(): JsonResponse
    {
        try {
            if (! Auth::check()) {
                return response()->json(['error' => 'Non authentifié'], 401);
            }

            $etudiantId  = Auth::id();
            $etudiant    = Utilisateur::find($etudiantId);

            // No affectation yet → return graceful empty response
            $affectation = Affectation::with('suiviPhases.phase', 'encadrant')
                ->where('etudiant_id', $etudiantId)
                ->first();

            if (! $affectation) {
                return response()->json([
                    'affectation_id' => null,
                    'titre_projet'   => null,
                    'encadrant_nom'  => null,
                    'phases'         => [],
                ]);
            }

            // Only show active phases to the student (same as PhaseController::index)
            $chef = Utilisateur::where('role', 'chef')
                ->where('specialite_id', $etudiant?->specialite_id)
                ->first();

            $phases = $chef
                ? Phase::where('chef_id', $chef->id)
                    ->where('active', true)
                    ->orderBy('ordre')
                    ->get()
                : collect();

            $suiviMap = $affectation->suiviPhases->keyBy('phase_id');

            $phasesData = $phases->map(function ($p) use ($suiviMap) {

                $suivi = $suiviMap->get($p->id);

                if ($suivi) {
                    $statut = $suivi->statut;
                } elseif ($p->terminee) {
                    $statut = 'terminee';
                } elseif ($p->active) {
                    $statut = 'en_cours';
                } else {
                    $statut = 'en_attente';
                }

                return [
                    'phase_id'              => $p->id,
                    'nom'                   => $p->nom,
                    'ordre'                 => $p->ordre,
                    'date_debut'            => $p->date_debut,
                    'date_fin'              => $p->date_fin,
                    'coefficient'           => $p->coefficient,
                    'livrable_obligatoire'  => $p->livrable_obligatoire,
                    'active'                => $p->active,
                    'terminee'              => $p->terminee,
                    'statut'                => $statut,
                    'commentaire_encadrant' => $suivi?->commentaire_encadrant,
                ];
            });

            return response()->json([
                'affectation_id' => $affectation->id,
                'titre_projet'   => $affectation->titre_projet,
                'encadrant_nom'  => trim(
                    (optional($affectation->encadrant)->nom    ?? '') . ' ' .
                    (optional($affectation->encadrant)->prenom ?? '')
                ),
                'phases' => $phasesData,
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'line'  => $e->getLine(),
            ], 500);
        }
    }

    /**
     * POST /api/suivi/valider
     *
     * Encadrant validates a student's phase.
     * Creates the suivi record if it doesn't exist yet
     * (possible when phase is active but student hasn't submitted livrable).
     */
    public function validerPhase(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'affectation_id'        => 'required|exists:affectations,id',
                'phase_id'              => 'required|exists:phases,id',
                'commentaire_encadrant' => 'nullable|string',
            ]);

            $suivi = SuiviEtudiantPhase::updateOrCreate(
                [
                    'affectation_id' => $data['affectation_id'],
                    'phase_id'       => $data['phase_id'],
                ],
                [
                    'statut'                => 'validee',
                    'date_validation'       => now(),
                    'commentaire_encadrant' => $data['commentaire_encadrant'] ?? null,
                ]
            );

            // Notify the student
            $affectation = Affectation::find($data['affectation_id']);
            $phase       = Phase::find($data['phase_id']);
            if ($affectation?->etudiant_id && $phase) {
                Notification::create([
                    'user_id' => $affectation->etudiant_id,
                    'message' => "La phase \"{$phase->nom}\" a été validée par votre encadrant.",
                    'created_at' => now(),
                ]);
            }

            return response()->json($suivi);

        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/suivi/rejeter
     *
     * Encadrant rejects a student's phase with a mandatory comment.
     * Also creates suivi record if missing.
     */
    public function rejeterPhase(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'affectation_id'        => 'required|exists:affectations,id',
                'phase_id'              => 'required|exists:phases,id',
                'commentaire_encadrant' => 'required|string',
            ]);

            $suivi = SuiviEtudiantPhase::updateOrCreate(
                [
                    'affectation_id' => $data['affectation_id'],
                    'phase_id'       => $data['phase_id'],
                ],
                [
                    'statut'                => 'rejetee',
                    'commentaire_encadrant' => $data['commentaire_encadrant'],
                ]
            );

            // Notify the student
            $affectation = Affectation::find($data['affectation_id']);
            $phase       = Phase::find($data['phase_id']);
            if ($affectation?->etudiant_id && $phase) {
                Notification::create([
                    'user_id' => $affectation->etudiant_id,
                    'message' => "La phase \"{$phase->nom}\" a été rejetée. Motif : {$data['commentaire_encadrant']}",
                    'created_at' => now(),
                ]);
            }

            return response()->json($suivi);

        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/suivi/historique/{affectationId}
     */
    public function historique(int $affectationId): JsonResponse
    {
        try {
            $affectation = Affectation::with('suiviPhases.phase')
                ->findOrFail($affectationId);

            $historique = $affectation->suiviPhases
                ->sortBy(fn($s) => optional($s->phase)->ordre)
                ->map(function ($s) {
                    return [
                        'id'          => $s->id,
                        'phase'       => optional($s->phase)->nom ?? '—',
                        'statut'      => $s->statut,
                        'commentaire' => $s->commentaire_encadrant,
                        'date'        => $s->date_validation
                            ? \Carbon\Carbon::parse($s->date_validation)->format('d/m/Y')
                            : ($s->date_lancement
                                ? \Carbon\Carbon::parse($s->date_lancement)->format('d/m/Y')
                                : '—'),
                    ];
                })
                ->values();

            return response()->json($historique);

        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}