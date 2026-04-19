<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Phase;
use App\Models\Notification;
use App\Models\Utilisateur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

        $phases = Phase::where('chef_id', $chef->id)
            ->where('active', true)
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
            'date_debut'           => 'required|date',
            'date_fin'             => 'required|date|after_or_equal:date_debut',
            'coefficient'          => 'required|numeric|min:0|max:10',
            'livrable_obligatoire' => 'boolean',
        ]);

        $maxOrdre = Phase::where('chef_id', Auth::id())->max('ordre') ?? 0;

        $phase = Phase::create([
            'chef_id'              => Auth::id(),
            'nom'                  => $data['nom'],
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
            $this->notifierActivation($phase);

            return response()->json($phase->fresh());
        }

        // ── 2. TERMINATION ─────────────────────────────────────────
        if (isset($data['terminee']) && $data['terminee'] === true) {

            if (! $phase->active) {
                return response()->json(['message' => 'Seule une phase active peut être terminée.'], 422);
            }

            $phase->update(['active' => false, 'terminee' => true]);
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

    // ─────────────────────────────────────────────────────────────────

    private function notifierActivation(Phase $phase): void
    {
        try {
            $chef = Utilisateur::find($phase->chef_id);
            if (! $chef) return;

            $destinataires = Utilisateur::where('specialite_id', $chef->specialite_id)
                ->whereIn('role', ['encadrant', 'etudiant'])
                ->pluck('id');

            $debut   = \Carbon\Carbon::parse($phase->date_debut)->format('d/m/Y');
            $fin     = \Carbon\Carbon::parse($phase->date_fin)->format('d/m/Y');
            $message = "La phase \"" . $phase->nom . "\" est maintenant active (du {$debut} au {$fin}).";

            foreach ($destinataires as $userId) {
                Notification::create([
                    'user_id'    => $userId,
                    'message'    => $message,
                    'created_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('PhaseController::notifierActivation: ' . $e->getMessage());
        }
    }
}