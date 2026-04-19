<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\FormulaireVoeux;
use App\Models\Utilisateur;
use App\Models\VoeuxEncadrement;

class FormulaireVoeuxController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->role === 'chef') {

            $formulaires = FormulaireVoeux::where('chef_id', $user->id)
                ->withCount([
                    'voeux as nb_reponses' => fn($q) => $q->where('statut', 'soumis')
                ])
                ->orderByDesc('created_at')
                ->get();

            return response()->json(
                $formulaires->map(fn($f) => $this->format($f))
            );
        }

        if (in_array($user->role, ['enseignant', 'encadrant'])) {

            if (!$user->specialite_id) {
                return response()->json([]);
            }

            $chefIds = Utilisateur::where('role', 'chef')
                ->where('specialite_id', $user->specialite_id)
                ->pluck('id');

            $formulaires = FormulaireVoeux::whereIn('chef_id', $chefIds)
                ->whereIn('statut', ['publie', 'verrouille'])
                ->orderByDesc('created_at')
                ->get();

            return response()->json(
                $formulaires->map(fn($f) => $this->format($f))
            );
        }

        return response()->json([]);
    }

    public function enseignantsDeMaSpecialite(Request $request)
    {
        $user = $request->user();

        if (!$user->specialite_id) {
            return response()->json([]);
        }

        return Utilisateur::where('specialite_id', $user->specialite_id)
            ->whereIn('role', ['enseignant', 'encadrant'])
            ->select('id', 'nom', 'prenom', 'email', 'role')
            ->get();
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'titre' => 'required|string',
            'date_limite' => 'required|date',
            'nb_max_etudiants' => 'nullable|integer',
            'champs' => 'required|array',
            'message' => 'nullable|string',
            'enseignants' => 'array',
        ]);

        $formulaire = FormulaireVoeux::create([
            'chef_id' => $user->id,
            'titre' => $request->titre,
            'date_limite' => $request->date_limite,
            'nb_max_etudiants' => $request->nb_max_etudiants ?? 3,
            'champs' => $request->champs,
            'message' => $request->message,
            'statut' => 'brouillon',
        ]);

        $this->syncEnseignants($formulaire, $request->enseignants ?? []);

        return response()->json([
            'message' => 'created',
            'formulaire' => $this->format($formulaire)
        ]);
    }

    public function update(Request $request, $id)
    {
        $user = $request->user();

        // Vérification explicite pour un message d'erreur clair
        $formulaire = FormulaireVoeux::find($id);

        if (!$formulaire) {
            return response()->json([
                'message' => "Formulaire introuvable (id={$id})"
            ], 404);
        }

        if ((int) $formulaire->chef_id !== (int) $user->id) {
            return response()->json([
                'message'            => "Accès refusé : vous n'êtes pas le chef de ce formulaire",
                'formulaire_chef_id' => $formulaire->chef_id,
                'user_id'            => $user->id,
            ], 403);
        }

        $request->validate([
            'titre'            => 'required|string',
            'date_limite'      => 'required|date',
            'nb_max_etudiants' => 'nullable|integer',
            'champs'           => 'required|array',
            'message'          => 'nullable|string',
            'enseignants'      => 'array',
        ]);

        $formulaire->update([
            'titre'            => $request->titre,
            'date_limite'      => $request->date_limite,
            'nb_max_etudiants' => $request->nb_max_etudiants ?? 3,
            'champs'           => $request->champs,
            'message'          => $request->message,
        ]);

        $this->syncEnseignants($formulaire, $request->enseignants ?? []);

        return response()->json([
            'message'    => 'updated',
            'formulaire' => $this->format($formulaire->fresh())
        ]);
    }

    private function syncEnseignants($formulaire, $enseignants)
    {
        // Supprimer uniquement les brouillons non remplis (préserver les réponses soumises)
        VoeuxEncadrement::where('formulaire_id', $formulaire->id)
            ->where('statut', 'brouillon')
            ->delete();

        // Récupérer les enseignants qui ont déjà une réponse (brouillon ou soumis)
        $existingIds = VoeuxEncadrement::where('formulaire_id', $formulaire->id)
            ->pluck('enseignant_id')
            ->toArray();

        // Insérer uniquement les nouveaux enseignants non encore présents
        foreach ($enseignants as $id) {
            if (!in_array($id, $existingIds)) {
                VoeuxEncadrement::create([
                    'formulaire_id' => $formulaire->id,
                    'enseignant_id' => $id,
                    'statut'        => 'brouillon',
                ]);
            }
        }
    }

    public function publier(Request $request, $id)
    {
        $formulaire = FormulaireVoeux::where('chef_id', $request->user()->id)
            ->findOrFail($id);

        $formulaire->update(['statut' => 'publie']);

        return response()->json([
            'message' => 'published',
            'formulaire' => $this->format($formulaire)
        ]);
    }

    public function verrouiller(Request $request, $id)
    {
        $formulaire = FormulaireVoeux::where('chef_id', $request->user()->id)
            ->findOrFail($id);

        $formulaire->update(['statut' => 'verrouille']);

        return response()->json([
            'message' => 'locked',
            'formulaire' => $this->format($formulaire)
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $formulaire = FormulaireVoeux::where('chef_id', $request->user()->id)
            ->findOrFail($id);

        $formulaire->delete();

        return response()->json(['message' => 'deleted']);
    }

    private function format($f)
    {
        return [
            'id' => $f->id,
            'titre' => $f->titre,
            'date_limite' => $f->date_limite,
            'nb_max_etudiants' => $f->nb_max_etudiants,
            'champs' => $f->champs,
            'message' => $f->message,
            'statut' => $f->statut,
            'chef_id' => $f->chef_id,
            'nb_reponses' => $f->nb_reponses ?? 0,
            'created_at' => $f->created_at,
        ];
    }
}