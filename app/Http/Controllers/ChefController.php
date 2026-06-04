<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Utilisateur;
use Carbon\Carbon;

class ChefController extends Controller
{
//liste chef : charger 
    public function index()
    {//ici on ai besoin de former un resultat avec les utilisateurs chef +jointure avec specialités
        $chefs = Utilisateur::where('role', 'chef')//filtrage
            ->with('specialite')
            ->get()//execution retourne une collection:objet 
            ->map(fn($c) => $this->format($c));//fonct flécher pour parcourer la colect et former des ligne de chaque reponse
        return response()->json($chefs);//transfomer reponse en json
    }
//les deux methode d'ajouter chef : recherche et promouvoir 
    public function rechercher(Request $request)
    {
        $request->validate(['q' => 'required|string']);//on verif la validité du chaine entré

        $q = $request->q;//stoke dans var q
        //cherche dans la colonne email q ou bien dans matricule 
        $user = Utilisateur::where('email', $q)
            ->orWhere('matricule', $q)
            ->first();

        if (!$user) {//utilisateur non trouvé
            return response()->json(['message' => 'Aucun utilisateur trouvé avec cet email.'], 404);
        }

        if ($user->role === 'chef') {//utilisateur est un chef déjà
            return response()->json(['message' => 'Cet utilisateur est déjà chef de département.'], 422);
        }

        if ($user->role === 'admin' || $user->role === 'directeur') {//utilisateur ne peut pas etre un chef 
            return response()->json(['message' => 'Cet utilisateur ne peut pas être promu chef.'], 422);
        }
//sinon on retourne les données de l'utilisateur trouvé
        return response()->json([
            'id'        => $user->id,
            'nom'       => $user->nom,
            'prenom'    => $user->prenom,
            'email'     => $user->email,
            'role'      => $user->role,
            'matricule' => $user->matricule,
        ]);
    }

//requete de promotion: post
    public function promouvoir(Request $request)
    {
        $request->validate([//on verif que l'utilisateur existe déjà
            'utilisateurId'    => 'required|exists:utilisateurs,id',
            'domaineExpertise' => 'nullable|string|max:200',
        ]);

        $user = Utilisateur::findOrFail($request->utilisateurId);//on recherche l'utilisateur dans la table par son id

        if ($user->role === 'chef') {//on verifit qu'il peut etre un chef 
            return response()->json(['message' => 'Déjà chef.'], 422);
        }

        $user->update([// udate role on chef et domaine optionnel
            'role'              => 'chef',
            'domaine_expertise' => $request->domaineExpertise ?: null,
        ]);
//retourne une reponse json forlmaté : que les champs necessaires, avec une jointure de la table specialités 
        return response()->json($this->format($user->load('specialite')), 201);//code http:created
    }

//methode d'affectation from comp affecter 
    public function affecter(Request $request, $id)//req avec l'id
    {//on verif aue l'id de la spec existe dans la table table
        $request->validate(['specialiteId' => 'required|exists:specialites,id']);
//uniq les utilisateurs chefs qui sont affecté à la mm spec et qui ne sont pas le chef actuel
        Utilisateur::where('role', 'chef')
            ->where('specialite_id', $request->specialiteId)
            ->where('id', '!=', $id)
            ->update(['specialite_id' => null, 'date_affectation' => null]);

        $chef = Utilisateur::findOrFail($id);//on cherche le nouveau chef by id
        $chef->update([//et on effectut l'affectation , avec le date sys
            'specialite_id'    => $request->specialiteId,
            'date_affectation' => Carbon::now()->format('Y-m-d'),
        ]);
//retourne la reponse avec une jointure en json formaté  
        return response()->json($this->format($chef->load('specialite')));
    }

//retirer listechef
    public function retirer(Request $request, $id)
    {
        $chef = Utilisateur::findOrFail($id);

        // le chef perd sa role on restaure toujours 'encadrant'
        $chef->update([
            'role'             => 'encadrant',

        ]);

        return response()->json($this->format($chef->load('specialite')));
    }

  //modification : listeschef 
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
//format utiliser dans index : une méthode privé recoit un objet utilisateur et le retourne en tab
    private function format(Utilisateur $c): array
    {
        return [//tab associatif chaque clé et son valeur 
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
            'dateAffectation'  => $c->date_affectation//retourne la date en format francais
                ? Carbon::parse($c->date_affectation)->format('d/m/Y')
                : '',
            'historique'       => [], //tableau d'historique vide
        ];
    }
}