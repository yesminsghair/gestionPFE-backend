<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Specialite;
use App\Models\Utilisateur;
use Carbon\Carbon;

class SpecialiteController extends Controller
{
    // GET /api/specialites
    public function index()
    {
        $specialites = Specialite::all();

        $chefs = Utilisateur::where('role', 'chef')
            ->whereNotNull('specialite_id')
            ->get(['id', 'nom', 'prenom', 'specialite_id']);

        $chefMap = $chefs->keyBy('specialite_id')
            ->map(fn($c) => trim("{$c->prenom} {$c->nom}"));

        $result = $specialites->map(function ($s) use ($chefMap) {
            return array_merge($s->toArray(), [
                'chef_nom' => $chefMap->get($s->id) ?? null,
            ]);
        });

        return response()->json($result);
    }

    // POST /api/specialites
    public function store(Request $request)
    {
        $request->validate([
            'nom'           => 'required|string|max:100',
            'code'          => 'required|string|max:20|unique:specialites,code',
            'description'   => 'nullable|string',
            'date_creation' => 'nullable|string',
            'capacite_max'  => 'nullable|integer|min:1|max:9999',
        ]);

        $data = $request->only(['nom', 'code', 'description', 'capacite_max']);

        if ($request->date_creation) {
            try {
                $data['date_creation'] = Carbon::createFromFormat('d/m/Y', $request->date_creation)->format('Y-m-d');
            } catch (\Exception $e) {
                $data['date_creation'] = null;
            }
        }

        $spec = Specialite::create($data);
        return response()->json($spec, 201);
    }

    // GET /api/specialites/{id}
    public function show($id)
    {
        return response()->json(Specialite::findOrFail($id));
    }

    // PUT /api/specialites/{id}
    public function update(Request $request, $id)
    {
        $spec = Specialite::findOrFail($id);

        $request->validate([
            'nom'           => 'required|string|max:100',
            'code'          => 'required|string|max:20|unique:specialites,code,' . $id,
            'description'   => 'nullable|string',
            'date_creation' => 'nullable|string',
            'capacite_max'  => 'nullable|integer|min:1|max:9999',
        ]);

        $data = $request->only(['nom', 'code', 'description', 'capacite_max']);

        if ($request->date_creation) {
            try {
                $data['date_creation'] = Carbon::createFromFormat('d/m/Y', $request->date_creation)->format('Y-m-d');
            } catch (\Exception $e) {
                $data['date_creation'] = null;
            }
        }

        $spec->update($data);
        return response()->json($spec);
    }

    // DELETE /api/specialites/{id}
    public function destroy($id)
    {
        Specialite::findOrFail($id)->delete();
        return response()->json(['message' => 'Spécialité supprimée.']);
    }
}