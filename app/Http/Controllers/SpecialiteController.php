<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Specialite;//eloquent model
use App\Models\Utilisateur;//model utilisateur pour la jointure
use Carbon\Carbon;

class SpecialiteController extends Controller
{
    // GET /api/specialites
    public function index() //declaration de  la fonction publique d'api rest 
    {//une jointure manuelle entre la table specialité et utilisateur pour avoir un tab associatif specialité_id, nom specialité,nom prenom chef 
        $specialites = Specialite::all();//recupere tous enr de la table via l'eloquent (modele)

        $chefs = Utilisateur::where('role', 'chef')//recuprere users who have the role chef 
            ->whereNotNull('specialite_id')//qui sont affecté a une spécialité 
            ->get(['id', 'nom', 'prenom', 'specialite_id']); //execute la req get de quelque données

        $chefMap = $chefs->keyBy('specialite_id')//transforme les données dans un tableau clé valeur: specialitéid = clé et l'objet utilisateur =valeur   
            ->map(fn($c) => trim("{$c->prenom} {$c->nom}"));//concatenation de nom prenomon suppression des espaces au debut et à la fin 
//
        $result = $specialites->map(function ($s) use ($chefMap) {//on parcours les specialites et le chef map pour trouver si chaque specialité a un chef
            return array_merge($s->toArray(), [
                'chef_nom' => $chefMap->get($s->id) ?? null,//cherche nom du chef dans chef map autour du specialité id sinom retourne null
            ]);
        });

        return response()->json($result);//convertit res en json et lenvoi as reponse http 
    }

//store() creer specialité
    public function store(Request $request)
    {
        $request->validate([
            'nom'           => 'required|string|max:100',
            'code'          => 'required|string|max:20|unique:specialites,code',
            'description'   => 'nullable|string',
            'date_creation' => 'nullable|string',
            'capacite_max'  => 'nullable|integer|min:1|max:9999',
        ]);
//extrait uniquement les champs necessaire, date de creation va etre traité séparement
        $data = $request->only(['nom', 'code', 'description', 'capacite_max']);

        if ($request->date_creation) {
            try {//si date creation existe dans le request
                $data['date_creation'] = Carbon::createFromFormat('d/m/Y', $request->date_creation)->format('Y-m-d');//crer la date de creation a partir du chaine au format francais 
            } catch (\Exception $e) {
                $data['date_creation'] = null;//si non null
            }
        }

        $spec = Specialite::create($data);//creer un nv enr
        return response()->json($spec, 201);//convert en json , code created
    }

  //cherche une specialitépar id pour l'affichage 
    public function show($id)
    {//si oui retourne l'objet si non 404 automatique
        return response()->json(Specialite::findOrFail($id));
    }
//modification
    public function update(Request $request, $id)
    {
        $spec = Specialite::findOrFail($id);//cherche la specialité par id 

        $request->validate([//on valide les données envoyé
            'nom'           => 'required|string|max:100',
            'code'          => 'required|string|max:20|unique:specialites,code,' . $id,
            'description'   => 'nullable|string',
            'date_creation' => 'nullable|string',
            'capacite_max'  => 'nullable|integer|min:1|max:9999',
        ]);
//extract les données nécessaire 
        $data = $request->only(['nom', 'code', 'description', 'capacite_max']);

        if ($request->date_creation) {//convert date en format francais
            try {
                $data['date_creation'] = Carbon::createFromFormat('d/m/Y', $request->date_creation)->format('Y-m-d');
            } catch (\Exception $e) { //si une erreur se produise retourne vide
                $data['date_creation'] = null;
            }
        }
//execute la modif 
        $spec->update($data);
        return response()->json($spec);//return reponse en json
    }

//supp d'une spec par id 
    public function destroy($id)
    {
        Specialite::findOrFail($id)->delete();//si on le trouve on delete
        return response()->json(['message' => 'Spécialité supprimée.']);
    }//retourne message de succes 
}