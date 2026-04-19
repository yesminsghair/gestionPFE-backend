<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Affectation;
use App\Models\Utilisateur;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function enseignantsDeMaSpecialite(): JsonResponse
    {
        $user = Auth::user();
        $specialiteId = $user->specialite_id;
        
        $enseignants = Utilisateur::where('specialite_id', $specialiteId)
            ->whereIn('role', ['enseignant', 'encadrant'])
            ->get(['id', 'nom', 'prenom', 'email']);
        
        return response()->json($enseignants);
    }

    public function affectationsDeMaSpecialite(): JsonResponse
    {
        $user = Auth::user();
        $specialiteId = $user->specialite_id;
        
        $affectations = Affectation::with(['etudiant', 'encadrant'])
            ->whereHas('etudiant', function($q) use ($specialiteId) {
                $q->where('specialite_id', $specialiteId);
            })
            ->get();
        
        return response()->json($affectations);
    }

    public function encadrantsDisponibles(): JsonResponse
    {
        $encadrants = Utilisateur::where('role', 'encadrant')
            ->withCount('affectationsEncadrant')
            ->get();
        
        return response()->json($encadrants);
    }
}