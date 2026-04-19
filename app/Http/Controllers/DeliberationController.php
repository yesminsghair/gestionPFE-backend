<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeliberationController extends Controller
{
    public function monResultat(Request $request)
    {
        $userId = $request->user()->id;

        $resultat = DB::table('deliberations')
            ->where('etudiant_id', $userId)
            ->first();

        return response()->json($resultat);
    }
}