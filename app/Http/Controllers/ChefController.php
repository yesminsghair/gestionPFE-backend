<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Utilisateur;
use Carbon\Carbon;

class ChefController extends Controller
{
    // GET /api/chefs
    public function index()
    {
        $chefs = Utilisateur::where('role', 'chef')
            ->with('specialite')
            ->get()
            ->map(fn($c) => $this->format($c));
        return response()->json($chefs);
    }

    // GET /api/chefs/rechercher?q=email_ou_matricule
    public function rechercher(Request $request)
    {
        $request->validate(['q' => 'required|string']);

        $q = $request->q;
        $user = Utilisateur::where('email', $q)
            ->orWhere('matricule', $q)
            ->first();

        if (!$user) {
            return response()->json(['message' => 'Aucun utilisateur trouvé avec cet email.'], 404);
        }

        if ($user->role === 'chef') {
            return response()->json(['message' => 'Cet utilisateur est déjà chef de département.'], 422);
        }

        if ($user->role === 'admin' || $user->role === 'directeur') {
            return response()->json(['message' => 'Cet utilisateur ne peut pas être promu chef.'], 422);
        }

        return response()->json([
            'id'        => $user->id,
            'nom'       => $user->nom,
            'prenom'    => $user->prenom,
            'email'     => $user->email,
            'role'      => $user->role,
            'matricule' => $user->matricule,
        ]);
    }

    // POST /api/chefs/promouvoir
    public function promouvoir(Request $request)
    {
        $request->validate([
            'utilisateurId'    => 'required|exists:utilisateurs,id',
            'domaineExpertise' => 'nullable|string|max:200',
        ]);

        $user = Utilisateur::findOrFail($request->utilisateurId);

        if ($user->role === 'chef') {
            return response()->json(['message' => 'Déjà chef.'], 422);
        }

        $user->update([
            'role'              => 'chef',
            'domaine_expertise' => $request->domaineExpertise ?: null,
        ]);

        return response()->json($this->format($user->load('specialite')), 201);
    }

    // POST /api/chefs/{id}/affecter
    public function affecter(Request $request, $id)
    {
        $request->validate(['specialiteId' => 'required|exists:specialites,id']);

        Utilisateur::where('role', 'chef')
            ->where('specialite_id', $request->specialiteId)
            ->where('id', '!=', $id)
            ->update(['specialite_id' => null, 'date_affectation' => null]);

        $chef = Utilisateur::findOrFail($id);
        $chef->update([
            'specialite_id'    => $request->specialiteId,
            'date_affectation' => Carbon::now()->format('Y-m-d'),
        ]);

        return response()->json($this->format($chef->load('specialite')));
    }

    // POST /api/chefs/{id}/retirer
    public function retirer(Request $request, $id)
    {
        $chef = Utilisateur::findOrFail($id);

        // Un chef est toujours un encadrant à la base — on restaure toujours 'encadrant'
        $chef->update([
            'role'             => 'encadrant',
            'specialite_id'    => null,
            'date_affectation' => null,
        ]);

        if ($request->supprimerCompte) {
            return response()->json(['deleted' => true]);
        }

        return response()->json($this->format($chef->load('specialite')));
    }

    // PUT /api/chefs/{id}/modifier
    public function modifier(Request $request, $id)
    {
        $chef = Utilisateur::findOrFail($id);

        $request->validate([
            'nom'              => 'required|string|max:100',
            'prenom'           => 'required|string|max:100',
            'email'            => 'required|email|unique:utilisateurs,email,' . $id,
            'telephone'        => 'nullable|string|max:20',
            'domaineExpertise' => 'nullable|string|max:200',
        ]);

        $chef->update([
            'nom'               => $request->nom,
            'prenom'            => $request->prenom,
            'email'             => $request->email,
            'telephone'         => $request->telephone,
            'domaine_expertise' => $request->domaineExpertise ?: null,
        ]);

        return response()->json($this->format($chef->load('specialite')));
    }

    private function format(Utilisateur $c): array
    {
        return [
            'id'               => $c->id,
            'nom'              => $c->nom,
            'prenom'           => $c->prenom,
            'email'            => $c->email,
            'telephone'        => $c->telephone ?? '',
            'matricule'        => $c->matricule,
            'domaineExpertise' => $c->domaine_expertise ?? '',
            'specialiteId'     => $c->specialite_id,
            'specialiteNom'    => $c->specialite?->nom ?? '',
            'specialiteCode'   => $c->specialite?->code ?? '',
            'dateAffectation'  => $c->date_affectation
                ? Carbon::parse($c->date_affectation)->format('d/m/Y')
                : '',
            'historique'       => [],
        ];
    }
}