<?php

namespace App\Http\Controllers;

use App\Models\Livrable;
use App\Models\Phase;
use App\Models\Affectation;
use App\Models\Notification;
use App\Models\SuiviEtudiantPhase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class LivrableController extends Controller
{
    // GET /api/livrables — student's own livrables
    public function index()
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        return Livrable::with('phase')
            ->where('etudiant_id', $user->id)
            ->orderByDesc('depose_le')
            ->get();
    }

    // GET /api/livrables/encadrant — all livrables for the logged-in encadrant's students
    public function parEncadrant()
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $livrables = Livrable::with(['phase', 'etudiant'])
            ->whereIn('etudiant_id', function ($q) use ($user) {
                $q->select('etudiant_id')
                  ->from('affectations')
                  ->where('encadrant_id', $user->id);
            })
            ->orderByDesc('depose_le')
            ->get()
            ->map(function ($l) {
                return [
                    'id'           => $l->id,
                    'phase_id'     => $l->phase_id,
                    'phase_nom'    => optional($l->phase)->nom,
                    'etudiant_id'  => $l->etudiant_id,
                    'etudiant_nom' => trim((optional($l->etudiant)->nom ?? '') . ' ' . (optional($l->etudiant)->prenom ?? '')),
                    'fichier'      => $l->fichier,
                    'file_name'    => $l->fichier ? basename($l->fichier) : 'fichier.pdf',
                    'statut'       => $l->statut,   // en_attente | valide | rejete
                    'commentaire'  => $l->commentaire,
                    'verrouille'   => $l->verrouille,
                    'depose_le'    => $l->depose_le,
                ];
            });

        return response()->json($livrables);
    }

    // POST /api/livrables
    public function store(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $request->validate([
            'phase_id' => 'required|exists:phases,id',
            'fichier'  => 'required|file|mimes:pdf|max:20480',
        ]);

        $phase = Phase::findOrFail($request->phase_id);

        $exists = Livrable::where('phase_id', $phase->id)
            ->where('etudiant_id', $user->id)
            ->where('verrouille', true)
            ->first();

        if ($exists) {
            return response()->json(['message' => 'Livrable verrouillé'], 403);
        }

        $path = $request->file('fichier')->store('livrables/' . $user->id, 'public');

        $livrable = Livrable::create([
            'phase_id'    => $phase->id,
            'etudiant_id' => $user->id,
            'fichier'     => $path,
            'statut'      => 'en_attente',
            'depose_le'   => now(),
        ]);

        // Find the affectation
        $affectation = Affectation::where('etudiant_id', $user->id)->first();

        if ($affectation) {
            // Create/update suivi record so history reflects the deposit
            SuiviEtudiantPhase::updateOrCreate(
                [
                    'affectation_id' => $affectation->id,
                    'phase_id'       => $phase->id,
                ],
                [
                    'statut'         => 'en_cours',
                    'date_lancement' => now(),
                ]
            );

            // Notify encadrant
            if ($affectation->encadrant_id) {
                Notification::create([
                    'user_id' => $affectation->encadrant_id,
                    'message' => $user->nom . ' ' . $user->prenom . ' a déposé un livrable pour la phase "' . $phase->nom . '".',
                    'lu'      => false,
                ]);
            }
        }

        return response()->json($livrable->load('phase'), 201);
    }

    // DOWNLOAD
    public function download(Livrable $livrable)
    {
        return Storage::disk('public')->download($livrable->fichier);
    }

    // VALIDER
    public function valider(Request $request, Livrable $livrable)
    {
        $livrable->update([
            'statut'      => 'valide',
            'commentaire' => $request->commentaire,
        ]);

        // Update suivi history
        $affectation = Affectation::where('etudiant_id', $livrable->etudiant_id)->first();
        if ($affectation) {
            SuiviEtudiantPhase::updateOrCreate(
                [
                    'affectation_id' => $affectation->id,
                    'phase_id'       => $livrable->phase_id,
                ],
                [
                    'statut'                => 'validee',
                    'date_validation'       => now(),
                    'commentaire_encadrant' => $request->commentaire,
                ]
            );
        }

        Notification::create([
            'user_id' => $livrable->etudiant_id,
            'message' => 'Votre livrable pour la phase "' . optional($livrable->phase)->nom . '" a été validé ✓',
            'lu'      => false,
        ]);

        return $livrable->load('phase');
    }

    // REJETER
    public function rejeter(Request $request, Livrable $livrable)
    {
        $request->validate([
            'commentaire' => 'required|string'
        ]);

        $livrable->update([
            'statut'      => 'rejete',
            'commentaire' => $request->commentaire,
        ]);

        // Update suivi history
        $affectation = Affectation::where('etudiant_id', $livrable->etudiant_id)->first();
        if ($affectation) {
            SuiviEtudiantPhase::updateOrCreate(
                [
                    'affectation_id' => $affectation->id,
                    'phase_id'       => $livrable->phase_id,
                ],
                [
                    'statut'                => 'rejetee',
                    'commentaire_encadrant' => $request->commentaire,
                ]
            );
        }

        Notification::create([
            'user_id' => $livrable->etudiant_id,
            'message' => 'Votre livrable pour la phase "' . optional($livrable->phase)->nom . '" a été rejeté. Motif : ' . $request->commentaire,
            'lu'      => false,
        ]);

        return $livrable->load('phase');
    }

    // VERROUILLER
    public function verrouiller(Livrable $livrable)
    {
        $livrable->update(['verrouille' => true]);
        return $livrable;
    }

    // DELETE SAFE
    public function destroy(Livrable $livrable)
    {
        if ($livrable->verrouille) {
            return response()->json(['message' => 'Verrouillé'], 403);
        }

        if ($livrable->fichier) {
            Storage::disk('public')->delete($livrable->fichier);
        }

        $livrable->delete();

        return response()->json(['message' => 'Supprimé']);
    }
}