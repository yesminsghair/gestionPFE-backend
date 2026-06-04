<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\Utilisateur;
use App\Models\Compte;
use Carbon\Carbon;

class UtilisateurController extends Controller
{
  //req get pour consulter liste comptes 
    public function index()
    { //on commence opar une jointure entre les 2 tbles 
        $users = Utilisateur::with(['compte', 'specialite'])->get();//on execute avec get
        return response()->json($users->map(fn($u) => $this->format($u)));//format la reponse en tab asso puis transforme en json
    }

  //recupere les comptes en attent d'activ par l'admin
    public function pending()
    {
        $comptes = Compte::where('status', 'pending')//jointure à 3 tables , puis execution d'order by : tri les plus recents d'abord 
                         ->with(['utilisateur.specialite'])
                         ->orderBy('created_at', 'desc')
                         ->get();//execution du requete 

        return response()->json( //retourne un tableau formater transformé en json
            $comptes->map(fn($c) => $this->format($c->utilisateur, $c))
        );
    }

 //cherche l'utilisateur par son id et le reourne
    public function show(string $id)
    {//jointure de recherche entre compte et utilisateur
        $user = Utilisateur::with(['compte', 'specialite'])->findOrFail($id);
        return response()->json($this->format($user));
    }

//modifier profil: changer données personnelles 
    public function update(Request $request, string $id)
    { //recherche l'utilisateur par son id et le recupere
        $user = Utilisateur::findOrFail($id);

        $authUser = $request->user();//il ne doit pas etre l'admin lui meme 
        if ($authUser->id != $id && $authUser->role !== 'admin') {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }

        $request->validate([//valide les données decette utilisateur : sometimes que lorque le champ existe 
            'nom'               => 'sometimes|string|max:100',
            'prenom'            => 'sometimes|string|max:100',
            'email'             => 'sometimes|email|unique:utilisateurs,email,' . $id,
            'matricule'         => 'sometimes|nullable|string|max:50|regex:/^[A-Z]{2}-[0-9]{6}$/',
            'telephone'         => 'sometimes|nullable|string|max:20',  
            'etablissement'     => 'sometimes|nullable|string|max:255',
            'domaine_expertise' => 'sometimes|nullable|string|max:200',
        ], [
            'matricule.regex' => 'Le matricule doit être au format XX-000000 (ex : AD-597624).',
        ]);

        $user->update($request->only([ //
            'nom', 'prenom', 'email', 'matricule',
            'telephone',//modifier que les champs autorisé 
            'etablissement', 'domaine_expertise',
        ]));
//envoi l'utilisateur avec toutes ses relation (foreing keys)
        return response()->json($this->format($user->fresh(['compte', 'specialite'])));
    }

    // ── POST /api/utilisateurs/{id}/valider ─────────────────────
    public function valider(string $id)
    {//cherche l'utilisateur et son compte 
        $user = Utilisateur::with('compte')->findOrFail($id);

        if ($user->compte) {//s'il possede un enr dans compte :on active le compte 
            $user->compte->update([
                'status'       => 'active',
                'actif'        => true,
                'activated_at' => Carbon::now(),
            ]);
        } else { //si non on creer un nv compte 
            Compte::create([
                'utilisateur_id' => $user->id,
                'status'         => 'active',
                'actif'          => true,
                'activated_at'   => Carbon::now(),
            ]);
        }

        return response()->json([ //retourne reponse avec msg
            'message' => "Compte de {$user->prenom} {$user->nom} activé avec succès.",
            'user'    => $this->format($user->fresh(['compte', 'specialite'])),
        ]);
    }

  //req de rejet 
    public function rejeter(string $id)
    {//recherche
        $user = Utilisateur::with('compte')->findOrFail($id);
//s'il possede un compte on le disactive
        if ($user->compte) {
            $user->compte->update([
                'status' => 'inactive',
                'actif'  => false,
            ]);
        }
//reponse : msg + json
        return response()->json([
            'message' => "Compte de {$user->prenom} {$user->nom} rejeté.",
            'user'    => $this->format($user->fresh(['compte', 'specialite'])),
        ]);
    }

 //supprime le compte desactiver 
    public function destroy(string $id)
    {
        $user = Utilisateur::findOrFail($id);
        $user->delete();
        return response()->json(['message' => 'Utilisateur supprimé.']);
    }


    //fonction privé pour formater la stucture de reponse
    private function format(Utilisateur $user, ?Compte $compte = null): array
    {
        $c = $compte ?? $user->compte;

        return [
            'id'               => $user->id,
            'nom'              => $user->nom,
            'prenom'           => $user->prenom,
            'email'            => $user->email,
            'matricule'        => $user->matricule,
            'telephone'        => $user->telephone, 
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