<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Models\VoeuxEncadrement;
use App\Models\FormulaireVoeux;
use App\Models\Utilisateur;

class VoeuxEncadrementController extends Controller
{
    /**
     * GET own voeu for a given formulaire (enseignant)
     * GET /api/voeux-encadrement?formulaire_id=X
     */
    public function index(Request $request)
    {
        $request->validate([
            'formulaire_id' => 'required|exists:formulaires_voeux,id'
        ]);

        $voeu = VoeuxEncadrement::where('formulaire_id', $request->formulaire_id)
            ->where('enseignant_id', $request->user()->id)
            ->first();

        if (!$voeu) {
            return response()->json(null);
        }

        return response()->json($voeu);
    }

    /**
     * GET all voeux for a formulaire (chef view)
     * GET /api/voeux-encadrement/liste?formulaire_id=X
     */
    public function liste(Request $request)
    {
        $request->validate([
            'formulaire_id' => 'required|exists:formulaires_voeux,id'
        ]);

        $formulaire = FormulaireVoeux::where('chef_id', $request->user()->id)
            ->findOrFail($request->formulaire_id);

        $voeux = VoeuxEncadrement::with('enseignant:id,nom,prenom,email')
            ->where('formulaire_id', $request->formulaire_id)
            ->get()
            ->map(function ($v) {
                return [
                    'id'             => $v->id,
                    'enseignant_id'  => $v->enseignant_id,
                    'enseignant_nom' => $v->enseignant
                        ? $v->enseignant->prenom . ' ' . $v->enseignant->nom
                        : null,
                    'disponibilite'  => $v->disponibilite,
                    'nbre_max_pfe'   => $v->nbre_max_pfe,
                    'themes'         => $v->themes,
                    'encadrement'    => $v->encadrement,
                    'commentaire'    => $v->commentaire,
                    'cotutelle'      => $v->cotutelle,
                    'statut'         => $v->statut,
                    'soumis_at'      => $v->soumis_at?->format('d/m/Y'),
                ];
            });

        return response()->json($voeux);
    }

    /**
     * CREATE / UPDATE own voeu
     * POST /api/voeux-encadrement
     */
    public function store(Request $request)
    {
        $request->validate([
            'formulaire_id'  => 'required|exists:formulaires_voeux,id',
            'disponibilite'  => 'nullable|in:oui,partielle,non',
            'nbre_max_pfe'   => 'nullable|integer|min:0',
            'encadrement'    => 'nullable|string',
            'themes'         => 'nullable|string',
            'commentaire'    => 'nullable|string',
            'cotutelle'      => 'nullable|boolean',
            'statut'         => 'required|in:brouillon,soumis',
        ]);

        $formulaire = FormulaireVoeux::findOrFail($request->formulaire_id);

        if ($formulaire->statut === 'verrouille' && $request->statut === 'soumis') {
            return response()->json(['message' => 'locked'], 422);
        }

        $cap     = $formulaire->nb_max_etudiants ?? 10;
        $nbrePfe = min((int) ($request->nbre_max_pfe ?? 0), $cap);

        $wasAlreadySoumis = VoeuxEncadrement::where('formulaire_id', $request->formulaire_id)
            ->where('enseignant_id', $request->user()->id)
            ->where('statut', 'soumis')
            ->exists();

        $voeu = VoeuxEncadrement::updateOrCreate(
            [
                'formulaire_id' => $request->formulaire_id,
                'enseignant_id' => $request->user()->id,
            ],
            [
                'disponibilite'  => $request->disponibilite,
                'nbre_max_pfe'   => $nbrePfe,
                'encadrement'    => $request->encadrement,
                'themes'         => $request->themes,
                'commentaire'    => $request->commentaire,
                'cotutelle'      => $request->cotutelle ?? false,
                'statut'         => $request->statut,
                'soumis_at'      => $request->statut === 'soumis' ? Carbon::now() : null,
            ]
        );

        // Notify chef on first submission OR when re-submitting after update
        if ($request->statut === 'soumis') {
            FormulaireVoeuxController::notifierChefSoumission($voeu);
        }

        $roleChanged = false;

        if ($request->statut === 'soumis' &&
            in_array($request->disponibilite, ['oui', 'partielle'])) {

            $user = $request->user();

            if ($user->role === 'enseignant') {
                Utilisateur::where('id', $user->id)
                    ->update(['role' => 'encadrant']);

                $roleChanged = true;
            }
        }

        return response()->json([
            'message'      => 'ok',
            'voeu'         => $voeu,
            'role_changed' => $roleChanged,
            'new_role'     => $roleChanged ? 'encadrant' : null,
        ]);
    }
}