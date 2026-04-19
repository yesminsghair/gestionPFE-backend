<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Specialite;
use Carbon\Carbon;

class SpecialiteController extends Controller
{
    // GET /api/specialites
    public function index()
    {
        return response()->json(Specialite::all());
    }

    // POST /api/specialites
    public function store(Request $request)
    {
        $request->validate([
            'nom'           => 'required|string|max:100',
            'code'          => 'required|string|max:20|unique:specialites,code',
            'description'   => 'nullable|string',
            'date_creation' => 'nullable|string',
        ]);

        $data = $request->only(['nom', 'code', 'description']);

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
        ]);

        $data = $request->only(['nom', 'code', 'description']);

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