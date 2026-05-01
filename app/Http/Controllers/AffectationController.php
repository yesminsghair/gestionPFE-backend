<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use App\Models\Affectation;
use App\Models\Notification;
use App\Models\Utilisateur;

class AffectationController extends Controller
{
    // Cache key helper — unique per chef
    private function cacheKey(int $chefId): string
    {
        return 'affectation_mode_chef_' . $chefId;
    }

    // ── GET /api/affectations ────────────────────────────────────────────────
    public function index(Request $request)
    {
        $user = $request->user();

        $affs = Affectation::with(['etudiant.specialite', 'encadrant'])
            ->where('chef_id', $user->id)
            ->get();

        return response()->json($affs->map(fn($a) => $this->format($a)));
    }

    // ── GET /api/affectations/mode ───────────────────────────────────────────
    public function getMode(Request $request)
    {
        $user = $request->user();

        if ($user->role === 'etudiant') {
            $chef = Utilisateur::where('role', 'chef')
                ->where('specialite_id', $user->specialite_id)
                ->first();

            if (!$chef) {
                return response()->json(['mode' => null]);
            }

            // 1. Try cache first (fast)
            $mode = Cache::get($this->cacheKey($chef->id));

            // 2. DB fallback: read from any affectation row created by this chef
            if (!$mode) {
                $mode = Affectation::where('chef_id', $chef->id)
                    ->whereNotNull('mode')
                    ->orderBy('updated_at', 'desc')
                    ->value('mode');
            }

            // 3. If found in DB but not in cache, repopulate cache
            if ($mode) {
                Cache::put($this->cacheKey($chef->id), $mode, now()->addYear());
            }

            return response()->json(['mode' => $mode]);
        }

        // Chef reading their own mode
        $mode = Cache::get($this->cacheKey($user->id));

        if (!$mode) {
            $mode = Affectation::where('chef_id', $user->id)
                ->whereNotNull('mode')
                ->orderBy('updated_at', 'desc')
                ->value('mode');

            if ($mode) {
                Cache::put($this->cacheKey($user->id), $mode, now()->addYear());
            }
        }

        return response()->json(['mode' => $mode]);
    }

    // ── POST /api/affectations/save-mode ─────────────────────────────────────
    public function saveMode(Request $request)
    {
        $request->validate(['mode' => 'required|in:manuel,aleatoire,semi']);

        $chefId = $request->user()->id;

        Cache::put($this->cacheKey($chefId), $request->mode, now()->addYear());

        return response()->json(['message' => 'Mode enregistré', 'mode' => $request->mode]);
    }

    // ── GET /api/affectations/mon-affectation ────────────────────────────────
    public function monAffectation(Request $request)
    {
        $user = $request->user();

        if ($user->role !== 'etudiant') {
            return response()->json(null);
        }

        $aff = Affectation::with(['encadrant', 'etudiant.specialite'])
            ->where('etudiant_id', $user->id)
            ->where('statut', 'diffusee')
            ->first();

        return response()->json($aff ? $this->format($aff) : null);
    }

    // ── GET /api/affectations/mes-affectations ───────────────────────────────
    // Used by the encadrant dashboard to list their assigned students
    public function mesAffectations(Request $request)
    {
        $user = $request->user();

        $affs = Affectation::with(['etudiant.specialite'])
            ->where('encadrant_id', $user->id)
            ->where('statut', 'diffusee')
            ->get();

        return response()->json($affs->map(fn($a) => $this->format($a)));
    }

    // ── GET /api/affectations/encadrants-disponibles ─────────────────────────
    public function encadrantsDisponibles(Request $request)
    {
        $user = $request->user();

        $encadrants = Utilisateur::with('specialite')
            ->where('role', 'encadrant')
            ->when($user->specialite_id, fn($q) =>
                $q->where('specialite_id', $user->specialite_id)
            )
            ->get();

        return response()->json($encadrants->map(function ($e) {
            $nbAffectes = Affectation::where('encadrant_id', $e->id)->count();
            $capacite   = $e->capacite_max ?? 5;

            return [
                'id'          => $e->id,
                'nom'         => $e->nom,
                'prenom'      => $e->prenom,
                'nom_complet' => $e->prenom . ' ' . $e->nom,
                'email'       => $e->email,
                'telephone'   => $e->telephone ?? null,
                'domaine'     => $e->domaine ?? $e->specialite?->nom,
                'specialite'  => $e->specialite?->nom,
                'nb_affectes' => $nbAffectes,
                'disponible'  => $nbAffectes < $capacite,
            ];
        }));
    }

    // ── GET /api/affectations/etudiants-de-ma-specialite ────────────────────
    public function etudiantsDeMaSpecialite(Request $request)
    {
        $user = $request->user();

        return response()->json(
            Utilisateur::where('role', 'etudiant')
                ->where('specialite_id', $user->specialite_id)
                ->get()
                ->map(fn($e) => [
                    'id'         => $e->id,
                    'nom'        => $e->nom,
                    'prenom'     => $e->prenom,
                    'matricule'  => $e->matricule,
                    'specialite' => $e->specialite?->nom,
                ])
        );
    }

    // ── POST /api/affectations/batch ─────────────────────────────────────────
    public function batch(Request $request)
    {
        $request->validate([
            'mode'         => 'required|in:manuel,aleatoire,semi',
            'affectations' => 'required|array',
        ]);

        $chef = $request->user();

        foreach ($request->affectations as $row) {
            Affectation::updateOrCreate(
                ['etudiant_id' => $row['etudiant_id']],
                [
                    'chef_id'      => $chef->id,
                    'mode'         => $request->mode,
                    'encadrant_id' => $row['encadrant_id'] ?? null,
                    'statut'       => 'en_cours',
                ]
            );
        }

        return response()->json(['message' => 'Affectations enregistrées']);
    }

    // ── POST /api/affectations/diffuser ──────────────────────────────────────
    public function diffuser(Request $request)
    {
        $chefId = $request->user()->id;
        $chef   = $request->user();
        $mode   = Cache::get($this->cacheKey($chefId));

        // For accord-mutuel: build rows from accepted demandes
        if ($mode === 'manuel') {
            $demandes = \App\Models\DemandeEncadrement::with(['etudiant'])
                ->whereHas('etudiant', fn($q) =>
                    $q->where('specialite_id', $chef->specialite_id)
                )
                ->where('statut', 'acceptee')
                ->get();

            foreach ($demandes as $d) {
                Affectation::updateOrCreate(
                    ['etudiant_id' => $d->etudiant_id],
                    [
                        'chef_id'      => $chefId,
                        'encadrant_id' => $d->encadrant_id,
                        'mode'         => 'manuel',
                        'statut'       => 'en_cours',
                    ]
                );
            }
        }

        Affectation::where('chef_id', $chefId)
            ->update(['statut' => 'diffusee', 'diffuse_at' => Carbon::now()]);

        // Notify all encadrants who have been assigned at least one student
        $affectationsDiffusees = Affectation::where('chef_id', $chefId)
            ->whereNotNull('encadrant_id')
            ->with('etudiant')
            ->get()
            ->groupBy('encadrant_id');

        foreach ($affectationsDiffusees as $encadrantId => $affs) {
            $nbEtudiants = $affs->count();
            $nomChef = trim($chef->prenom . ' ' . $chef->nom);
            Notification::create([
                'user_id'    => $encadrantId,
                'message'    => "Les affectations ont été diffusées par {$nomChef}. Vous encadrez désormais {$nbEtudiants} étudiant(s).",
                'lu'         => false,
                'created_at' => now(),
            ]);
        }

        return response()->json(['message' => 'Diffusion réussie']);
    }

    // ── DELETE /api/affectations/reinitialiser ───────────────────────────────
    public function reinitialiser(Request $request)
    {
        $chefId = $request->user()->id;

        Affectation::where('chef_id', $chefId)->delete();

        Cache::forget($this->cacheKey($chefId));

        return response()->json(['message' => 'Réinitialisation OK']);
    }

    // ── Private ──────────────────────────────────────────────────────────────
    private function format($a)
    {
        return [
            'id'           => $a->id,
            'mode'         => $a->mode,
            'statut'       => $a->statut,
            'etudiant_id'  => $a->etudiant_id,
            'etudiant'     => $a->etudiant ? $a->etudiant->prenom . ' ' . $a->etudiant->nom : null,
            'matricule'    => $a->etudiant?->matricule,
            'specialite'   => $a->etudiant?->specialite?->nom,
            'encadrant_id' => $a->encadrant_id,
            'encadrant'    => $a->encadrant ? $a->encadrant->prenom . ' ' . $a->encadrant->nom : null,
        ];
    }
}