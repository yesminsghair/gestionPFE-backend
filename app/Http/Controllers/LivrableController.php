<?php

namespace App\Http\Controllers;

use App\Models\Livrable;
use App\Models\Phase;
use App\Models\Affectation;
use App\Models\Notification;
use App\Models\ProjetPfe;
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
        if (!$user) return response()->json(['message' => 'Unauthenticated'], 401);

        return response()->json(
            Livrable::with('phase')
                ->where('etudiant_id', $user->id)
                ->whereNotIn('statut', ['retire', 'remplace'])
                ->orderByDesc('depose_le')
                ->get()
                ->map(fn($l) => array_merge($l->toArray(), [
                    'fichier_url' => $l->fichier ? asset('storage/' . $l->fichier) : null,
                ]))
        );
    }

    // GET /api/livrables/historique
    public function historique()
    {
        $user = Auth::user();
        if (!$user) return response()->json(['message' => 'Unauthenticated'], 401);

        return response()->json(
            Livrable::with('phase')
                ->where('etudiant_id', $user->id)
                ->orderByDesc('depose_le')
                ->get()
                ->map(fn($l) => [
                    'id'          => $l->id,
                    'phase_id'    => $l->phase_id,
                    'phase_nom'   => optional($l->phase)->nom,
                    'file_name'   => $l->file_name ?: ($l->fichier ? basename($l->fichier) : 'fichier.pdf'),
                    'fichier_url' => $l->fichier ? asset('storage/' . $l->fichier) : null,
                    'statut'      => $l->statut,
                    'commentaire' => $l->commentaire,
                    'version'     => $l->version ?? 1,
                    'depose_le'   => $l->depose_le,
                ])
        );
    }

    // GET /api/livrables/encadrant
    public function parEncadrant()
    {
        $user = Auth::user();
        if (!$user) return response()->json(['message' => 'Unauthenticated'], 401);

        $all = Livrable::with(['phase', 'etudiant'])
            ->whereIn('etudiant_id', function ($q) use ($user) {
                $q->select('etudiant_id')
                  ->from('affectations')
                  ->where('encadrant_id', $user->id);
            })
            ->whereNotIn('statut', ['retire', 'remplace'])
            ->orderByDesc('depose_le')
            ->get();

        // Keep only the most recent submission per (etudiant, phase)
        $seen      = [];
        $livrables = $all->filter(function ($l) use (&$seen) {
            $key = $l->etudiant_id . '_' . $l->phase_id;
            if (isset($seen[$key])) return false;
            $seen[$key] = true;
            return true;
        })->map(function ($l) {
            return [
                'id'           => $l->id,
                'phase_id'     => $l->phase_id,
                'phase_nom'    => optional($l->phase)->nom,
                'etudiant_id'  => $l->etudiant_id,
                'etudiant_nom' => trim((optional($l->etudiant)->nom ?? '') . ' ' . (optional($l->etudiant)->prenom ?? '')),
                'fichier'      => $l->fichier,
                'fichier_url'  => $l->fichier ? asset('storage/' . $l->fichier) : null,  // ← FIXED
                'file_name'    => $l->file_name ?: ($l->fichier ? basename($l->fichier) : 'fichier.pdf'),
                'statut'       => $l->statut,
                'commentaire'  => $l->commentaire,
                'verrouille'   => $l->verrouille,
                'version'      => $l->version ?? 1,
                'remplace_le'  => $l->remplace_le ?? null,
                'depose_le'    => $l->depose_le,
            ];
        });

        return response()->json($livrables->values());
    }

    // POST /api/livrables
    public function store(Request $request)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['message' => 'Unauthenticated'], 401);

        $request->validate([
            'phase_id' => 'required|exists:phases,id',
            'fichier'  => 'required|file|mimes:pdf|max:20480',
        ]);

        $phase = Phase::findOrFail($request->phase_id);

        if (Livrable::where('phase_id', $phase->id)->where('etudiant_id', $user->id)->where('verrouille', true)->exists()) {
            return response()->json(['message' => 'Livrable verrouillé'], 403);
        }

        $originalName = $request->file('fichier')->getClientOriginalName();
        $path         = $request->file('fichier')->store('livrables/' . $user->id, 'public');

        // Detect whether this is a replacement BEFORE we soft-delete the old one
        $previousLivrable = Livrable::where('phase_id', $phase->id)
            ->where('etudiant_id', $user->id)
            ->where('verrouille', false)
            ->whereNull('remplace_le')
            ->latest('depose_le')
            ->first();

        $isReplacement   = $previousLivrable !== null;
        $previousVersion = $isReplacement ? ($previousLivrable->version ?? 1) : 0;

        // Soft-replace: stamp previous livrables with remplace_le so history is preserved
        if ($isReplacement) {
            $previousLivrable->update(['remplace_le' => now(), 'statut' => 'remplace']);
        }

        $livrable = Livrable::create([
            'phase_id'    => $phase->id,
            'etudiant_id' => $user->id,
            'fichier'     => $path,
            'file_name'   => $originalName,
            'statut'      => 'en_attente',
            'version'     => $previousVersion + 1,
            'depose_le'   => now(),
        ]);

        $affectation = Affectation::where('etudiant_id', $user->id)->first();
        $etudiantNom = trim($user->prenom . ' ' . $user->nom);

        if ($affectation) {
            SuiviEtudiantPhase::updateOrCreate(
                ['affectation_id' => $affectation->id, 'phase_id' => $phase->id],
                ['statut' => 'en_cours', 'date_lancement' => now()]
            );

            if ($affectation->encadrant_id) {
                if ($isReplacement) {
                    Notification::create([
                        'user_id'    => $affectation->encadrant_id,
                        'message'    => "{$etudiantNom} a remplacé son livrable pour la phase \"{$phase->nom}\" (version {$livrable->version}).",
                        'lu'         => false,
                        'created_at' => now(),
                    ]);
                } else {
                    Notification::create([
                        'user_id'    => $affectation->encadrant_id,
                        'message'    => "{$etudiantNom} a déposé un livrable pour la phase \"{$phase->nom}\".",
                        'lu'         => false,
                        'created_at' => now(),
                    ]);
                }
            }

            ProjetPfe::firstOrCreate(
                ['etudiant_id' => $user->id],
                [
                    'encadrant_id' => $affectation->encadrant_id,
                    'titre'        => $affectation->titre_projet ?? $originalName,
                    'description'  => $affectation->description ?? '',
                    'specialite'   => optional($user->specialite)->nom ?? null,
                ]
            );
        }

        return response()->json(array_merge($livrable->load('phase')->toArray(), [
            'fichier_url' => $livrable->fichier ? asset('storage/' . $livrable->fichier) : null,
        ]), 201);
    }

    // GET /api/livrables/{livrable}/download
    public function download(Livrable $livrable)
    {
        $fullPath = Storage::disk('public')->path($livrable->fichier);
        return response()->file($fullPath, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . basename($livrable->fichier) . '"',
        ]);
    }

    // PUT /api/livrables/{livrable}/valider
    public function valider(Request $request, Livrable $livrable)
    {
        $encadrant    = Auth::user();
        $encadrantNom = trim(($encadrant->prenom ?? '') . ' ' . ($encadrant->nom ?? ''));

        $livrable->update(['statut' => 'valide', 'commentaire' => $request->commentaire]);

        $affectation = Affectation::where('etudiant_id', $livrable->etudiant_id)->first();

        if ($affectation) {
            SuiviEtudiantPhase::updateOrCreate(
                ['affectation_id' => $affectation->id, 'phase_id' => $livrable->phase_id],
                ['statut' => 'validee', 'date_validation' => now(), 'commentaire_encadrant' => $request->commentaire]
            );

            ProjetPfe::firstOrCreate(
                ['etudiant_id' => $livrable->etudiant_id],
                [
                    'encadrant_id' => $affectation->encadrant_id,
                    'titre'        => $affectation->titre_projet ?? $livrable->file_name ?? 'Projet PFE',
                    'description'  => $affectation->description ?? '',
                    'specialite'   => optional(optional($affectation->etudiant)->specialite)->nom ?? null,
                ]
            );
        }

        $phaseNom = optional($livrable->phase)->nom ?? 'cette phase';
        Notification::create([
            'user_id'    => $livrable->etudiant_id,
            'message'    => "Votre livrable pour la phase \"{$phaseNom}\" a été validé par {$encadrantNom}.",
            'lu'         => false,
            'created_at' => now(),
        ]);

        return response()->json($livrable->load('phase'));
    }

    // PUT /api/livrables/{livrable}/rejeter
    public function rejeter(Request $request, Livrable $livrable)
    {
        $request->validate(['commentaire' => 'required|string']);

        $encadrant    = Auth::user();
        $encadrantNom = trim(($encadrant->prenom ?? '') . ' ' . ($encadrant->nom ?? ''));

        $livrable->update(['statut' => 'rejete', 'commentaire' => $request->commentaire]);

        $affectation = Affectation::where('etudiant_id', $livrable->etudiant_id)->first();
        if ($affectation) {
            SuiviEtudiantPhase::updateOrCreate(
                ['affectation_id' => $affectation->id, 'phase_id' => $livrable->phase_id],
                ['statut' => 'rejetee', 'commentaire_encadrant' => $request->commentaire]
            );
        }

        $phaseNom = optional($livrable->phase)->nom ?? 'cette phase';
        Notification::create([
            'user_id'    => $livrable->etudiant_id,
            'message'    => "{$encadrantNom} a rejeté votre livrable pour \"{$phaseNom}\". Raison : {$request->commentaire}",
            'lu'         => false,
            'created_at' => now(),
        ]);

        return response()->json($livrable->load('phase'));
    }

    // PUT /api/livrables/{livrable}/verrouiller
    public function verrouiller(Livrable $livrable)
    {
        $livrable->update(['verrouille' => true]);
        return response()->json($livrable);
    }

    // DELETE /api/livrables/{livrable}
    public function destroy(Livrable $livrable)
    {
        if ($livrable->verrouille) {
            return response()->json(['message' => 'Verrouillé'], 403);
        }

        // Soft-delete: mark as retired so history is preserved
        $livrable->update([
            'statut'      => 'retire',
            'remplace_le' => now(),
        ]);

        // Also remove the suivi record so the encadrant's view resets for this phase
        $affectation = Affectation::where('etudiant_id', $livrable->etudiant_id)->first();
        if ($affectation) {
            SuiviEtudiantPhase::where('affectation_id', $affectation->id)
                ->where('phase_id', $livrable->phase_id)
                ->delete();
        }

        return response()->json(['message' => 'Retiré']);
    }
}