<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CategorieGrille;
use App\Models\CritereEvaluation;
use App\Models\GrilleEvaluation;
use App\Models\Notification;
use App\Models\Utilisateur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class GrilleEvaluationController extends Controller
{
    /**
     * GET /api/grilles
     *
     * chef      → his own grilles (all statuses)
     * directeur → ALL submitted/validated grilles (publie | verrouille)
     * encadrant → validated grilles visible to encadrants/jurys, scoped by speciality
     * jury      → validated grilles visible to jurys, scoped by speciality
     * others    → 403
     */
    public function index(): JsonResponse
    {
        $user = Auth::user();

        if ($user->role === 'chef') {
            $grilles = GrilleEvaluation::with(['categories.criteres', 'chef.specialite'])
                ->where('chef_id', $user->id)
                ->latest()
                ->get();
            return response()->json($grilles->map(fn($g) => $this->format($g)));
        }

        if ($user->role === 'directeur') {
            $grilles = GrilleEvaluation::with(['categories.criteres', 'chef.specialite'])
                ->whereIn('statut', ['publie', 'verrouille'])
                ->latest()
                ->get();
            return response()->json($grilles->map(fn($g) => $this->format($g)));
        }

        if (in_array($user->role, ['encadrant', 'jury'])) {
            // verrouille = validated by directeur = visible to all encadrants & jury members.
            $grilles = GrilleEvaluation::with(['categories.criteres', 'chef.specialite'])
                ->where('statut', 'verrouille')
                ->latest()
                ->get();
            return response()->json($grilles->map(fn($g) => $this->format($g)));
        }

        // Any user who is a member of at least one jury (président / examinateur)
        // can also read verrouille grilles — they need it to fill the evaluation form.
        $isJuryMember = \App\Models\JuryMembrePfe::where('enseignant_id', $user->id)->exists();
        if ($isJuryMember) {
            $grilles = GrilleEvaluation::with(['categories.criteres', 'chef.specialite'])
                ->where('statut', 'verrouille')
                ->latest()
                ->get();
            return response()->json($grilles->map(fn($g) => $this->format($g)));
        }

        // Students and all other roles — no access to grilles
        return response()->json([], 403);
    }

    // POST /api/grilles
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['nom' => 'required|string|max:255']);

        $grille = GrilleEvaluation::create([
            'chef_id' => Auth::id(),
            'nom'     => $data['nom'],
            'statut'  => 'brouillon',
        ]);

        return response()->json($grille->load('categories.criteres'), 201);
    }

    // GET /api/grilles/{id}
    public function show(GrilleEvaluation $grille): JsonResponse
    {
        return response()->json(
            $this->format($grille->load('categories.criteres', 'chef.specialite'))
        );
    }

    /**
     * PUT /api/grilles/{id}
     * Editing is blocked once publie or verrouille. Accepts: nom
     */
    public function update(Request $request, GrilleEvaluation $grille): JsonResponse
    {
        if (in_array($grille->statut, ['publie', 'verrouille'])) {
            return response()->json(['message' => 'Grille non modifiable (soumise ou validée).'], 403);
        }
        $data = $request->validate(['nom' => 'sometimes|string|max:255']);
        $grille->update($data);
        return response()->json($grille->load('categories.criteres'));
    }

    // DELETE /api/grilles/{id}
    public function destroy(GrilleEvaluation $grille): JsonResponse
    {
        if (in_array($grille->statut, ['publie', 'verrouille'])) {
            return response()->json(['message' => 'Grille non modifiable, suppression impossible.'], 403);
        }

        $grille->delete();
        return response()->json(['message' => 'Grille supprimée.']);
    }

    /**
     * POST /api/grilles/{id}/publier
     *
     * Chef submits the grille to the directeur for validation.
     * Status: brouillon → publie
     * Action: notify directeur(s) with chef name + speciality.
     */
    public function publier(GrilleEvaluation $grille): JsonResponse
    {
        if ($grille->statut === 'verrouille') {
            return response()->json(['message' => 'Déjà validée et verrouillée.'], 422);
        }
        if ($grille->statut === 'publie') {
            return response()->json(['message' => 'Déjà soumise au directeur.'], 422);
        }

        $grille->update(['statut' => 'publie', 'publie_le' => now()]);
        $this->notifierDirecteur($grille);

        return response()->json($grille->fresh());
    }

    /**
     * POST /api/grilles/{id}/verrouiller
     *
     * Two callers:
     *   - Chef: locks his own brouillon grille (was already possible before)
     *   - Directeur: validates a submitted (publie) grille → locks it officially
     *
     * When called by the directeur on a publie grille, the chef gets a
     * "validated" notification. When called by the chef directly on a brouillon,
     * no special notification is needed.
     */
    public function verrouiller(GrilleEvaluation $grille): JsonResponse
    {
        $user    = Auth::user();
        $isDirecteur = $user->role === 'directeur';
        $wasPublie   = $grille->statut === 'publie';

        $grille->update(['statut' => 'verrouille', 'verrouille_le' => now()]);

        // Notify the owning chef only when the directeur validates
        if ($isDirecteur && $wasPublie) {
            $this->notifierChefValidation($grille);
        }

        return response()->json($grille->fresh());
    }

    // ─── Categories ───────────────────────────────────────────────────

    // POST /api/grilles/{id}/categories
    public function addCategorie(Request $request, GrilleEvaluation $grille): JsonResponse
    {
        if (in_array($grille->statut, ['publie', 'verrouille'])) {
            return response()->json(['message' => 'Grille non modifiable.'], 403);
        }

        $data = $request->validate([
            'nom'        => 'required|string|max:255',
            'bareme_max' => 'required|numeric|min:0|max:20',
            'color'      => 'sometimes|string|max:20',
            'position'   => 'sometimes|integer|min:1',
        ]);

        $data['grille_id'] = $grille->id;
        $data['position']  = $data['position'] ?? ($grille->categories()->max('position') + 1);

        $categorie = CategorieGrille::create($data);
        return response()->json($categorie->load('criteres'), 201);
    }

    // PUT /api/grilles/{id}/categories/{catId}
    public function updateCategorie(Request $request, GrilleEvaluation $grille, CategorieGrille $categorie): JsonResponse
    {
        if (in_array($grille->statut, ['publie', 'verrouille'])) {
            return response()->json(['message' => 'Grille non modifiable.'], 403);
        }

        $data = $request->validate([
            'nom'        => 'sometimes|string|max:255',
            'bareme_max' => 'sometimes|numeric|min:0|max:20',
            'color'      => 'sometimes|string|max:20',
            'position'   => 'sometimes|integer|min:1',
        ]);

        $categorie->update($data);
        return response()->json($categorie->load('criteres'));
    }

    // DELETE /api/grilles/{id}/categories/{catId}
    public function deleteCategorie(GrilleEvaluation $grille, CategorieGrille $categorie): JsonResponse
    {
        if (in_array($grille->statut, ['publie', 'verrouille'])) {
            return response()->json(['message' => 'Grille non modifiable.'], 403);
        }

        $categorie->delete();
        return response()->json(['message' => 'Catégorie supprimée.']);
    }

    // ─── Criteres ─────────────────────────────────────────────────────

    // POST /api/grilles/{id}/categories/{catId}/criteres
    public function addCritere(Request $request, GrilleEvaluation $grille, CategorieGrille $categorie): JsonResponse
    {
        if (in_array($grille->statut, ['publie', 'verrouille'])) {
            return response()->json(['message' => 'Grille non modifiable.'], 403);
        }

        $data = $request->validate([
            'nom'        => 'required|string|max:255',
            'bareme_max' => 'required|numeric|min:0',
            'position'   => 'sometimes|integer|min:1',
        ]);

        $data['categorie_id'] = $categorie->id;
        $data['position']     = $data['position'] ?? ($categorie->criteres()->max('position') + 1);

        $critere = CritereEvaluation::create($data);
        return response()->json($critere, 201);
    }

    // PUT /api/criteres/{critereId}
    public function updateCritere(Request $request, CritereEvaluation $critere): JsonResponse
    {
        $grilleId = optional($critere->categorie)->grille_id;
        if ($grilleId) {
            $grille = GrilleEvaluation::find($grilleId);
            if ($grille && in_array($grille->statut, ['publie', 'verrouille'])) {
                return response()->json(['message' => 'Grille non modifiable.'], 403);
            }
        }

        $data = $request->validate([
            'nom'        => 'sometimes|string|max:255',
            'bareme_max' => 'sometimes|numeric|min:0',
            'position'   => 'sometimes|integer|min:1',
        ]);

        $critere->update($data);
        return response()->json($critere);
    }

    // DELETE /api/criteres/{critereId}
    public function deleteCritere(CritereEvaluation $critere): JsonResponse
    {
        $grilleId = optional($critere->categorie)->grille_id;
        if ($grilleId) {
            $grille = GrilleEvaluation::find($grilleId);
            if ($grille && in_array($grille->statut, ['publie', 'verrouille'])) {
                return response()->json(['message' => 'Grille non modifiable.'], 403);
            }
        }

        $critere->delete();
        return response()->json(['message' => 'Critère supprimé.']);
    }

    // ─── Private helpers ──────────────────────────────────────────────

    private function format(GrilleEvaluation $grille): array
    {
        $chef = $grille->chef;
        return array_merge($grille->toArray(), [
            'chef_nom'        => $chef ? trim($chef->prenom . ' ' . $chef->nom) : '—',
            'chef_specialite' => optional($chef?->specialite)->nom,
        ]);
    }

    /** Notify ALL directeur users when a chef submits a grille. */
    private function notifierDirecteur(GrilleEvaluation $grille): void
    {
        try {
            $chef       = Utilisateur::with('specialite')->find($grille->chef_id);
            $chefNom    = $chef ? trim($chef->prenom . ' ' . $chef->nom) : 'Un chef';
            $specialite = optional($chef?->specialite)->nom ?? '—';

            $message = "Le chef {$chefNom} (spécialité : {$specialite}) "
                     . "est en attente de validation pour sa grille d'évaluation.";

            Utilisateur::where('role', 'directeur')
                ->pluck('id')
                ->each(fn($id) => Notification::create([
                    'user_id'    => $id,
                    'message'    => $message,
                    'created_at' => now(),
                ]));
        } catch (\Throwable $e) {
            Log::warning('GrilleEvaluationController::notifierDirecteur: ' . $e->getMessage());
        }
    }

    /** Notify the chef that the directeur validated his grille. */
    private function notifierChefValidation(GrilleEvaluation $grille): void
    {
        try {
            Notification::create([
                'user_id'    => $grille->chef_id,
                'message'    => "Votre grille d'évaluation a été validée par le directeur de stage.",
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('GrilleEvaluationController::notifierChefValidation: ' . $e->getMessage());
        }
    }
}