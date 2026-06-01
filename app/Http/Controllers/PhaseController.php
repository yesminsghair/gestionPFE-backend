<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Phase;
use App\Models\Livrable;
use App\Models\Notification;
use App\Models\SuiviEtudiantPhase;
use App\Models\Utilisateur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PhaseController extends Controller
{
    /**
     * GET /api/phases
     *
     * Chef      → ALL his phases (full management view)
     * Others    → ACTIVE phases only (from the chef of their speciality)
     */
    public function index(): JsonResponse
    {
        $user = Auth::user();

        if ($user->role === 'chef') {
            $phases = Phase::where('chef_id', $user->id)
                ->orderBy('ordre')
                ->get();
            return response()->json($phases);
        }

        // Non-chef: find the chef of the same speciality, return only active phases
        $chef = Utilisateur::where('role', 'chef')
            ->where('specialite_id', $user->specialite_id)
            ->first();

        if (! $chef) {
            return response()->json([]);
        }

        // Return all phases that are active OR already terminated
        // so the student sees the full progression (terminated ones stay visible)
        $phases = Phase::where('chef_id', $chef->id)
            ->where(function ($q) {
                $q->where('active', true)
                  ->orWhere('terminee', true);
            })
            ->orderBy('ordre')
            ->get();

        return response()->json($phases);
    }

    /**
     * GET /api/phases/{phase}  — kept for any consumer that uses it
     */
    public function show(Phase $phase): JsonResponse
    {
        return response()->json($phase);
    }

    /**
     * POST /api/phases
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nom'                  => 'required|string|max:255',
            'description'          => 'nullable|string',
            'date_debut'           => 'required|date',
            'date_fin'             => 'required|date|after_or_equal:date_debut',
            'coefficient'          => 'required|numeric|min:0|max:10',
            'livrable_obligatoire' => 'boolean',
        ]);

        $maxOrdre = Phase::where('chef_id', Auth::id())->max('ordre') ?? 0;

        $phase = Phase::create([
            'chef_id'              => Auth::id(),
            'nom'                  => $data['nom'],
            'description'          => $data['description'] ?? null,
            'ordre'                => $maxOrdre + 1,
            'date_debut'           => $data['date_debut'],
            'date_fin'             => $data['date_fin'],
            'coefficient'          => $data['coefficient'],
            'livrable_obligatoire' => $data['livrable_obligatoire'] ?? false,
            'active'               => false,
            'terminee'             => false,
        ]);

        return response()->json($phase, 201);
    }

    /**
     * PUT /api/phases/{phase}
     *
     * Handles four scenarios dispatched by request fields:
     *   1. active=true   → Activation + sequential guard
     *   2. terminee=true → Termination
     *   3. livrable_obligatoire only → always allowed
     *   4. Normal edit   → only on inactive, non-done phases
     */
    public function update(Request $request, Phase $phase): JsonResponse
    {
        if ($phase->chef_id !== Auth::id()) {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        $data = $request->validate([
            'nom'                  => 'sometimes|string|max:255',
            'description'          => 'sometimes|nullable|string',
            'date_debut'           => 'sometimes|date',
            'date_fin'             => 'sometimes|date|after_or_equal:date_debut',
            'coefficient'          => 'sometimes|numeric|min:0|max:10',
            'livrable_obligatoire' => 'sometimes|boolean',
            'active'               => 'sometimes|boolean',
            'terminee'             => 'sometimes|boolean',
        ]);

        // ── 1. ACTIVATION ──────────────────────────────────────────
        if (isset($data['active']) && $data['active'] === true) {

            if ($phase->terminee) {
                return response()->json(['message' => 'Cette phase est déjà terminée.'], 422);
            }
            if ($phase->active) {
                return response()->json(['message' => 'Cette phase est déjà active.'], 422);
            }

            // Sequential guard: immediate predecessor (by ordre) must be terminee
            $previous = Phase::where('chef_id', Auth::id())
                ->where('ordre', '<', $phase->ordre)
                ->orderByDesc('ordre')
                ->first();

            if ($previous && ! $previous->terminee) {
                return response()->json([
                    'message' => 'La phase précédente ("' . $previous->nom
                               . '") doit être terminée avant d\'activer celle-ci.',
                ], 422);
            }

            // Safety: deactivate any other currently-active phase of this chef
            Phase::where('chef_id', Auth::id())
                ->where('id', '!=', $phase->id)
                ->where('active', true)
                ->update(['active' => false]);

            $phase->update(['active' => true, 'terminee' => false]);
            $debut   = \Carbon\Carbon::parse($phase->date_debut)->format('d/m/Y');
            $fin     = \Carbon\Carbon::parse($phase->date_fin)->format('d/m/Y');
            $this->notifierPhase($phase, "La phase \"{$phase->nom}\" est maintenant active (du {$debut} au {$fin}).");

            return response()->json($phase->fresh());
        }

        // ── 2. TERMINATION ─────────────────────────────────────────
        if (isset($data['terminee']) && $data['terminee'] === true) {

            if (! $phase->active) {
                return response()->json(['message' => 'Seule une phase active peut être terminée.'], 422);
            }

            $phase->update(['active' => false, 'terminee' => true]);
            $this->notifierPhase($phase, "La phase \"{$phase->nom}\" a été clôturée par le chef de département.");
            return response()->json($phase->fresh());
        }

        // ── 3+4. STANDARD EDIT ─────────────────────────────────────
        if ($phase->active || $phase->terminee) {
            $allowed = array_intersect_key($data, array_flip(['livrable_obligatoire']));
            if (empty($allowed)) {
                return response()->json([
                    'message' => 'Impossible de modifier une phase active ou terminée.',
                ], 422);
            }
            $phase->update($allowed);
        } else {
            $phase->update($data);
        }

        return response()->json($phase->fresh());
    }

    /**
     * PUT /api/phases/reorder
     */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phases'         => 'required|array',
            'phases.*.id'    => 'required|exists:phases,id',
            'phases.*.ordre' => 'required|integer|min:1',
        ]);

        DB::transaction(function () use ($data) {
            foreach ($data['phases'] as $item) {
                Phase::where('id', $item['id'])
                    ->where('chef_id', Auth::id())
                    ->update(['ordre' => $item['ordre']]);
            }
        });

        return response()->json(['message' => 'Ordre mis à jour']);
    }

    /**
     * DELETE /api/phases/{phase}
     */
    public function destroy(Phase $phase): JsonResponse
    {
        if ($phase->chef_id !== Auth::id()) {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        if ($phase->active || $phase->terminee) {
            return response()->json([
                'message' => 'Impossible de supprimer une phase active ou terminée.',
            ], 422);
        }

        $phase->delete();
        return response()->json(['message' => 'Phase supprimée']);
    }

    /**
     * POST /api/phases/reinitialiser
     *
     * Resets ALL phases of this chef to inactive/non-terminated,
     * then wipes all livrable files + records and all suivi records
     * for students of this chef's speciality.
     * The phases themselves are kept intact (dates, coefficients, names).
     */
    public function reinitialiser(): JsonResponse
    {
        $chef = Auth::user();
        if ($chef->role !== 'chef') {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        DB::transaction(function () use ($chef) {

            // 1. Reset all phases → active=false, terminee=false
            Phase::where('chef_id', $chef->id)
                ->update(['active' => false, 'terminee' => false]);

            // 2. Collect all student IDs of this speciality
            $etudiantIds = Utilisateur::where('role', 'etudiant')
                ->where('specialite_id', $chef->specialite_id)
                ->pluck('id');

            // 3. Delete livrable files from disk then wipe the records
            $livrables = Livrable::whereIn('etudiant_id', $etudiantIds)->get();
            foreach ($livrables as $lv) {
                if ($lv->fichier) {
                    \Illuminate\Support\Facades\Storage::disk('public')->delete($lv->fichier);
                }
            }
            Livrable::whereIn('etudiant_id', $etudiantIds)->delete();

            // 4. Wipe all suivi records for these students
            $affectationIds = \App\Models\Affectation::whereIn('etudiant_id', $etudiantIds)
                ->pluck('id');
            SuiviEtudiantPhase::whereIn('affectation_id', $affectationIds)->delete();
        });

        return response()->json([
            'message' => 'Toutes les phases ont été réinitialisées. Les livrables et le suivi ont été effacés.',
        ]);
    }

    /**
     * GET /api/phases/livrable-stats
     *
     * Chef only — for each active phase, returns how many students of the
     * chef's speciality have submitted at least one livrable vs. total students.
     *
     * Response shape:
     * [
     *   { phase_id, phase_nom, submitted, total, percent, date_fin, jours_restants }
     * ]
     */
    public function livrableStats(): JsonResponse
    {
        $chef = Auth::user();
        if ($chef->role !== 'chef') {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        // Total students for this speciality
        $totalEtudiants = Utilisateur::where('role', 'etudiant')
            ->where('specialite_id', $chef->specialite_id)
            ->count();

        // Active phases of this chef
        $activePhases = Phase::where('chef_id', $chef->id)
            ->where('active', true)
            ->get();

        $stats = $activePhases->map(function (Phase $phase) use ($totalEtudiants) {
            // Count distinct students who submitted a livrable for this phase
            $submitted = Livrable::where('phase_id', $phase->id)
                ->distinct('etudiant_id')
                ->count('etudiant_id');

            $joursRestants = $phase->date_fin
                ? (int) now()->startOfDay()->diffInDays(
                    \Carbon\Carbon::parse($phase->date_fin)->startOfDay(),
                    false   // signed: negative = past
                  )
                : null;

            return [
                'phase_id'       => $phase->id,
                'phase_nom'      => $phase->nom,
                'submitted'      => $submitted,
                'total'          => $totalEtudiants,
                'percent'        => $totalEtudiants > 0
                    ? round($submitted / $totalEtudiants * 100)
                    : 0,
                'date_fin'       => $phase->date_fin,
                'jours_restants' => $joursRestants,
            ];
        });

        return response()->json($stats);
    }

    // ─────────────────────────────────────────────────────────────────

    /**
     * Notify students (by specialite) AND encadrants (via affectations)
     * when a phase is activated or terminated.
     */
    private function notifierPhase(Phase $phase, string $message): void
    {
        try {
            $chef = Utilisateur::find($phase->chef_id);
            if (! $chef) return;

            // 1. Students of this speciality
            $etudiantIds = Utilisateur::where('role', 'etudiant')
                ->where('specialite_id', $chef->specialite_id)
                ->pluck('id');

            // 2. Encadrants linked to those students via affectations
            $encadrantIds = \App\Models\Affectation::whereIn('etudiant_id', $etudiantIds)
                ->whereNotNull('encadrant_id')
                ->pluck('encadrant_id')
                ->unique();

            $destinataires = $etudiantIds->merge($encadrantIds)->unique();

            foreach ($destinataires as $userId) {
                Notification::create([
                    'user_id'    => $userId,
                    'message'    => $message,
                    'lu'         => false,
                    'created_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('PhaseController::notifierPhase: ' . $e->getMessage()
                . ' | ' . $e->getFile() . ':' . $e->getLine());
        }
    }
}