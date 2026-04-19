<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\Reunion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReunionController extends Controller
{
    // GET /api/reunions
    // L'encadrant voit toutes ses réunions ; l'étudiant voit les siennes
    public function index(Request $request): JsonResponse
    {
        $user  = Auth::user();
        $query = Reunion::with('encadrant', 'etudiant');

        if ($user->role === 'encadrant') {
            $query->where('encadrant_id', $user->id);
        } elseif ($user->role === 'etudiant') {
            $query->where('etudiant_id', $user->id);
        }

        // Filtres optionnels transmis par le front
        if ($request->has('statut')) {
            $query->where('statut', $request->statut);
        }
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        $reunions = $query->orderByDesc('date_reunion')->get()->map(fn($r) => [
            'id'             => $r->id,
            'date_reunion'   => $r->date_reunion,
            'type'           => $r->type,
            'statut'         => $r->statut,
            'lieu'           => $r->lieu,
            'compte_rendu'   => $r->compte_rendu,
            'motif'          => $r->motif,
            'encadrant_nom'  => optional($r->encadrant)->nom . ' ' . optional($r->encadrant)->prenom,
            'etudiant_nom'   => optional($r->etudiant)->nom . ' ' . optional($r->etudiant)->prenom,
        ]);

        return response()->json($reunions);
    }

    // POST /api/reunions  — encadrant crée une réunion
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'etudiant_id'  => 'required|exists:utilisateurs,id',
            'date_reunion' => 'required|date',
            'type'         => 'required|in:presentiel,distanciel,mixte',
            'lieu'         => 'nullable|string|max:255',
        ]);

        $data['encadrant_id'] = Auth::id();
        $data['statut']       = 'planifiee';

        $reunion = Reunion::create($data);

        // Notifier l'étudiant
        $dateFormatee = \Carbon\Carbon::parse($data['date_reunion'])->format('d/m/Y à H:i');
        Notification::create([
            'user_id'    => $data['etudiant_id'],
            'message'    => "Une réunion ({$data['type']}) a été planifiée pour le {$dateFormatee}.",
            'created_at' => now(),
        ]);

        return response()->json($reunion->load('encadrant', 'etudiant'), 201);
    }

    // GET /api/reunions/{reunion}
    public function show(Reunion $reunion): JsonResponse
    {
        return response()->json($reunion->load('encadrant', 'etudiant'));
    }

    // PUT /api/reunions/{reunion}
    public function update(Request $request, Reunion $reunion): JsonResponse
    {
        $data = $request->validate([
            'date_reunion' => 'sometimes|date',
            'type'         => 'sometimes|in:presentiel,distanciel,mixte',
            'statut'       => 'sometimes|in:planifiee,confirmee,annulee,effectuee',
            'lieu'         => 'nullable|string|max:255',
            'compte_rendu' => 'nullable|string',
            'motif'        => 'nullable|string',
        ]);

        $reunion->update($data);

        return response()->json($reunion);
    }

    // DELETE /api/reunions/{reunion}
    public function destroy(Reunion $reunion): JsonResponse
    {
        $reunion->delete();
        return response()->json(['message' => 'Réunion supprimée.']);
    }

    // POST /api/reunions/{reunion}/confirmer  — étudiant confirme
    public function confirmer(Reunion $reunion): JsonResponse
    {
        $reunion->update(['statut' => 'confirmee']);

        Notification::create([
            'user_id'    => $reunion->encadrant_id,
            'message'    => "L'étudiant a confirmé la réunion du " .
                \Carbon\Carbon::parse($reunion->date_reunion)->format('d/m/Y à H:i') . '.',
            'created_at' => now(),
        ]);

        return response()->json($reunion);
    }

    // POST /api/reunions/{reunion}/annuler
    public function annuler(Request $request, Reunion $reunion): JsonResponse
    {
        $data = $request->validate([
            'motif' => 'nullable|string',
        ]);

        $reunion->update(['statut' => 'annulee', 'motif' => $data['motif'] ?? null]);

        return response()->json($reunion);
    }

    // POST /api/reunions/{reunion}/compte-rendu  — encadrant rédige le CR
    public function compteRendu(Request $request, Reunion $reunion): JsonResponse
    {
        $data = $request->validate([
            'compte_rendu' => 'required|string',
        ]);

        $reunion->update([
            'compte_rendu' => $data['compte_rendu'],
            'statut'       => 'effectuee',
        ]);

        Notification::create([
            'user_id'    => $reunion->etudiant_id,
            'message'    => 'Le compte-rendu de votre réunion a été rédigé.',
            'created_at' => now(),
        ]);

        return response()->json($reunion);
    }
}