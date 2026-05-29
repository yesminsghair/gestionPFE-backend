<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\Utilisateur;
use App\Models\Compte;
use Carbon\Carbon;

class UtilisateurController extends Controller
{
    // ── GET /api/utilisateurs ────────────────────────────────────
    public function index()
    {
        $users = Utilisateur::with(['compte', 'specialite'])->get();
        return response()->json($users->map(fn($u) => $this->format($u)));
    }

    // ── GET /api/utilisateurs/pending ───────────────────────────
    public function pending()
    {
        $comptes = Compte::where('status', 'pending')
                         ->with(['utilisateur.specialite'])
                         ->orderBy('created_at', 'desc')
                         ->get();

        return response()->json(
            $comptes->map(fn($c) => $this->format($c->utilisateur, $c))
        );
    }

    // ── GET /api/utilisateurs/{id} ───────────────────────────────
    public function show(string $id)
    {
        $user = Utilisateur::with(['compte', 'specialite'])->findOrFail($id);
        return response()->json($this->format($user));
    }

    // ── PUT /api/utilisateurs/{id} ───────────────────────────────
    public function update(Request $request, string $id)
    {
        $user = Utilisateur::findOrFail($id);

        $authUser = $request->user();
        if ($authUser->id != $id && $authUser->role !== 'admin') {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }

        $request->validate([
            'nom'               => 'sometimes|string|max:100',
            'prenom'            => 'sometimes|string|max:100',
            'email'             => 'sometimes|email|unique:utilisateurs,email,' . $id,
            'matricule'         => 'sometimes|nullable|string|max:50|regex:/^[A-Z]{2}-[0-9]{6}$/',
            'telephone'         => 'sometimes|nullable|string|max:20',  // ← ajouté
            'etablissement'     => 'sometimes|nullable|string|max:255',
            'domaine_expertise' => 'sometimes|nullable|string|max:200',
        ], [
            'matricule.regex' => 'Le matricule doit être au format XX-000000 (ex : AD-597624).',
        ]);

        $user->update($request->only([
            'nom', 'prenom', 'email', 'matricule',
            'telephone',           // ← ajouté
            'etablissement', 'domaine_expertise',
        ]));

        return response()->json($this->format($user->fresh(['compte', 'specialite'])));
    }

    // ── POST /api/utilisateurs/{id}/valider ─────────────────────
    public function valider(string $id)
    {
        $user = Utilisateur::with('compte')->findOrFail($id);

        if ($user->compte) {
            $user->compte->update([
                'status'       => 'active',
                'actif'        => true,
                'activated_at' => Carbon::now(),
            ]);
        } else {
            Compte::create([
                'utilisateur_id' => $user->id,
                'status'         => 'active',
                'actif'          => true,
                'activated_at'   => Carbon::now(),
            ]);
        }

        return response()->json([
            'message' => "Compte de {$user->prenom} {$user->nom} activé avec succès.",
            'user'    => $this->format($user->fresh(['compte', 'specialite'])),
        ]);
    }

    // ── POST /api/utilisateurs/{id}/rejeter ─────────────────────
    public function rejeter(string $id)
    {
        $user = Utilisateur::with('compte')->findOrFail($id);

        if ($user->compte) {
            $user->compte->update([
                'status' => 'inactive',
                'actif'  => false,
            ]);
        }

        return response()->json([
            'message' => "Compte de {$user->prenom} {$user->nom} rejeté.",
            'user'    => $this->format($user->fresh(['compte', 'specialite'])),
        ]);
    }

    // ── DELETE /api/utilisateurs/{id} ───────────────────────────
    public function destroy(string $id)
    {
        $user = Utilisateur::findOrFail($id);
        $user->delete();
        return response()->json(['message' => 'Utilisateur supprimé.']);
    }

    // ── POST /api/utilisateurs (store) ──────────────────────────
    public function store(Request $request)
    {
        $request->validate([
            'nom'       => 'required|string|max:100',
            'prenom'    => 'required|string|max:100',
            'email'     => 'required|email|unique:utilisateurs,email',
            'password'  => 'required|string|min:8',
            'role'      => 'required|in:admin,directeur,chef,encadrant,enseignant,etudiant,jury',
            'telephone' => 'nullable|string|max:20',  // ← ajouté
        ]);

        $user = Utilisateur::create([
            'nom'           => $request->nom,
            'prenom'        => $request->prenom,
            'email'         => $request->email,
            'password'      => Hash::make($request->password),
            'role'          => $request->role,
            'matricule'     => $request->matricule,
            'telephone'     => $request->telephone,   // ← ajouté
            'etablissement' => $request->etablissement,
        ]);

        Compte::create([
            'utilisateur_id'    => $user->id,
            'email_verified_at' => now(),
            'status'            => 'active',
            'actif'             => true,
            'activated_at'      => now(),
        ]);

        return response()->json($this->format($user->fresh(['compte', 'specialite'])), 201);
    }

    // ── Helper : formater la réponse ─────────────────────────────
    private function format(Utilisateur $user, ?Compte $compte = null): array
    {
        $c = $compte ?? $user->compte;

        return [
            'id'               => $user->id,
            'nom'              => $user->nom,
            'prenom'           => $user->prenom,
            'email'            => $user->email,
            'matricule'        => $user->matricule,
            'telephone'        => $user->telephone,    // ← ajouté
            'role'             => $user->role,
            'etablissement'    => $user->etablissement,
            'domaine_expertise'=> $user->domaine_expertise,
            'specialite_id'    => $user->specialite_id,
            'specialite'       => $user->specialite,
            'date_affectation' => $user->date_affectation,
            'created_at'       => $user->created_at,
            'updated_at'       => $user->updated_at,
            'status'           => $c?->status,
            'actif'            => $c?->actif,
            'email_verified_at'=> $c?->email_verified_at,
            'activated_at'     => $c?->activated_at,
        ];
    }
}