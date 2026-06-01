<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\DemandeEncadrement;
use App\Models\Utilisateur;
use App\Models\Affectation;
use App\Models\Notification;
use App\Models\ProjetPfe;

class DemandeEncadrementController extends Controller
{
    // ── GET /api/demandes-encadrement ─────────────────────────────
    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->role === 'etudiant') {
            // Supprimer automatiquement les demandes expirées (> 7 jours sans réponse)
            DemandeEncadrement::where('etudiant_id', $user->id)
                ->where('statut', 'en_attente')
                ->where('created_at', '<', now()->subDays(7))
                ->delete();

            // L'étudiant voit SA demande active
            $demande = DemandeEncadrement::with(['encadrant'])
                ->where('etudiant_id', $user->id)
                ->whereIn('statut', ['en_attente', 'acceptee', 'rejetee'])
                ->latest()
                ->first();

            if (!$demande) return response()->json(null);
            return response()->json($this->format($demande));
        }

        if ($user->role === 'encadrant') {
            // L'encadrant voit les demandes qui lui sont adressées
            $demandes = DemandeEncadrement::with(['etudiant.specialite'])
                ->where('encadrant_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->get();
            return response()->json($demandes->map(fn($d) => $this->format($d)));
        }

        // Chef : demandes des étudiants de sa spécialité
        $demandes = DemandeEncadrement::with(['etudiant.specialite', 'encadrant'])
            ->whereHas('etudiant', fn($q) => $q->where('specialite_id', $user->specialite_id))
            ->orderBy('created_at', 'desc')
            ->get();
        return response()->json($demandes->map(fn($d) => $this->format($d)));
    }

    // ── POST /api/demandes-encadrement ────────────────────────────
    public function store(Request $request)
    {
        $request->validate([
            'sujet'        => 'required|string|max:255',
            'description'  => 'required|string',
            'encadrant_id' => 'required|exists:utilisateurs,id',
            'doc_pdf'      => 'nullable|file|mimes:pdf,doc,docx|max:5120',
        ]);

        $etudiant = $request->user();

        // Supprimer automatiquement les demandes expirées (> 7 jours sans réponse)
        DemandeEncadrement::where('etudiant_id', $etudiant->id)
            ->where('statut', 'en_attente')
            ->where('created_at', '<', now()->subDays(7))
            ->delete();

        // Vérifier qu'il n'a pas déjà une demande active (en_attente ou acceptee)
        $existante = DemandeEncadrement::where('etudiant_id', $etudiant->id)
            ->whereIn('statut', ['en_attente', 'acceptee'])
            ->first();

        if ($existante) {
            return response()->json([
                'message' => 'Vous avez déjà une demande en cours. Annulez-la avant d\'en soumettre une nouvelle.'
            ], 422);
        }

        // Upload fichier
        $docPath = null;
        if ($request->hasFile('doc_pdf')) {
            $docPath = $request->file('doc_pdf')->store('demandes', 'public');
        }

        $demande = DemandeEncadrement::create([
            'sujet'        => $request->sujet,
            'description'  => $request->description,
            'encadrant_id' => $request->encadrant_id,
            'etudiant_id'  => $etudiant->id,
            'statut'       => 'en_attente',
            'date_demande' => now(),
            'doc_pdf'      => $docPath,
        ]);

        // Notifier l'encadrant
        Notification::create([
            'user_id' => $request->encadrant_id,
            'titre'   => 'Nouvelle demande d\'encadrement',
            'message' => "{$etudiant->prenom} {$etudiant->nom} vous a envoyé une demande d'encadrement pour le sujet : « {$demande->sujet} ».",
            'type'    => 'demande',
            'lu'      => false,
        ]);

        return response()->json([
            'message' => 'Demande soumise avec succès.',
            'demande' => $this->format($demande->load(['encadrant', 'etudiant.specialite'])),
        ], 201);
    }

    // ── PUT /api/demandes-encadrement/{id} ────────────────────────
    public function update(Request $request, string $id)
    {
        $demande = DemandeEncadrement::findOrFail($id);

        // Seul l'étudiant propriétaire peut modifier, seulement si en_attente
        if ($demande->etudiant_id !== $request->user()->id) {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }
        if ($demande->statut !== 'en_attente') {
            return response()->json(['message' => 'Cette demande ne peut plus être modifiée.'], 422);
        }

        $request->validate([
            'sujet'        => 'sometimes|string|max:255',
            'description'  => 'sometimes|string',
            'encadrant_id' => 'sometimes|exists:utilisateurs,id',
            'doc_pdf'      => 'nullable|file|mimes:pdf,doc,docx|max:5120',
        ]);

        // Upload nouveau fichier si fourni
        if ($request->hasFile('doc_pdf')) {
            $demande->doc_pdf = $request->file('doc_pdf')->store('demandes', 'public');
        }

        if ($request->filled('sujet'))        $demande->sujet        = $request->sujet;
        if ($request->filled('description'))  $demande->description  = $request->description;
        if ($request->filled('encadrant_id')) $demande->encadrant_id = (int)$request->encadrant_id;
        $demande->save();

        return response()->json([
            'message' => 'Demande mise à jour.',
            'demande' => $this->format($demande->load(['encadrant', 'etudiant.specialite'])),
        ]);
    }

    // ── POST /api/demandes-encadrement/{id}/modifier ─────────────
    // Dedicated multipart-safe update endpoint — avoids PUT/POST confusion
    public function modifier(Request $request, string $id)
    {
        $demande = DemandeEncadrement::findOrFail($id);

        if ((int)$demande->etudiant_id !== (int)$request->user()->id) {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }
        if ($demande->statut !== 'en_attente') {
            return response()->json(['message' => 'Cette demande ne peut plus être modifiée.'], 422);
        }

        $request->validate([
            'sujet'        => 'sometimes|string|max:255',
            'description'  => 'sometimes|string',
            'encadrant_id' => 'sometimes|integer|exists:utilisateurs,id',
            'doc_pdf'      => 'sometimes|nullable|file|mimes:pdf|max:10240',
        ]);

        if ($request->filled('sujet'))        $demande->sujet        = $request->sujet;
        if ($request->filled('description'))  $demande->description  = $request->description;
        if ($request->filled('encadrant_id')) $demande->encadrant_id = (int)$request->encadrant_id;

        if ($request->hasFile('doc_pdf')) {
            $demande->doc_pdf = $request->file('doc_pdf')->store('demandes', 'public');
        }

        $demande->save();

        return response()->json([
            'message' => 'Demande mise à jour.',
            'demande' => $this->format($demande->load(['encadrant', 'etudiant.specialite'])),
        ]);
    }

    // ── DELETE /api/demandes-encadrement/{id} ─────────────────────
    public function destroy(Request $request, string $id)
    {
        $demande = DemandeEncadrement::findOrFail($id);

        if ($demande->etudiant_id !== $request->user()->id) {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }
        if ($demande->statut !== 'en_attente') {
            return response()->json(['message' => 'Seules les demandes en attente peuvent être annulées.'], 422);
        }

        $demande->delete();
        return response()->json(['message' => 'Demande annulée avec succès.']);
    }

    // ── DELETE /api/demandes-encadrement/{id}/reset ───────────────
    // Supprime une demande REJETÉE pour permettre à l'étudiant d'en soumettre une nouvelle
    public function reset(Request $request, string $id)
    {
        $demande = DemandeEncadrement::findOrFail($id);

        if ((int)$demande->etudiant_id !== (int)$request->user()->id) {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }

        if ($demande->statut !== 'rejetee') {
            return response()->json([
                'message' => 'Seules les demandes rejetées peuvent être supprimées via cette action.'
            ], 422);
        }

        $demande->delete();

        return response()->json([
            'message' => 'Vous pouvez maintenant soumettre une nouvelle demande.',
            'reset'   => true,
        ]);
    }

    // ── POST /api/demandes-encadrement/{id}/accepter ──────────────
    public function accepter(Request $request, string $id)
    {
        $demande = DemandeEncadrement::with(['etudiant.specialite', 'encadrant'])->findOrFail($id);

        if ($demande->encadrant_id !== $request->user()->id) {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }

        $demande->update([
            'statut'    => 'acceptee',
            'traite_at' => now(),
        ]);

        // ── 1. Créer / mettre à jour le ProjetPfe ────────────────
        $specialiteNom = optional($demande->etudiant?->specialite)->nom ?? null;

        $projet = ProjetPfe::updateOrCreate(
            ['etudiant_id' => $demande->etudiant_id],
            [
                'encadrant_id' => $demande->encadrant_id,
                'titre'        => $demande->sujet,
                'description'  => $demande->description ?? '',
                'specialite'   => $specialiteNom,
            ]
        );

        // ── 2. Créer / mettre à jour l'Affectation ───────────────
        Affectation::updateOrCreate(
            ['etudiant_id' => $demande->etudiant_id],
            [
                'encadrant_id'  => $demande->encadrant_id,
                'titre_projet'  => $demande->sujet,
                'description'   => $demande->description ?? '',
            ]
        );

        // ── 3. Notifier l'étudiant ────────────────────────────────
        Notification::create([
            'user_id' => $demande->etudiant_id,
            'titre'   => 'Demande d\'encadrement acceptée',
            'message' => "Votre demande d'encadrement pour le sujet « {$demande->sujet} » a été acceptée par {$demande->encadrant->prenom} {$demande->encadrant->nom}.",
            'type'    => 'acceptation',
            'lu'      => false,
        ]);

        return response()->json([
            'message' => 'Demande acceptée.',
            'demande' => $this->format($demande->load(['encadrant', 'etudiant'])),
            'projet'  => $projet->id,
        ]);
    }

    // ── POST /api/demandes-encadrement/{id}/rejeter ───────────────
    public function rejeter(Request $request, string $id)
    {
        $request->validate(['motif_rejet' => 'required|string']);

        $demande = DemandeEncadrement::findOrFail($id);

        if ($demande->encadrant_id !== $request->user()->id) {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }

        $demande->update([
            'statut'       => 'rejetee',
            'motif_rejet'  => $request->motif_rejet,
            'traite_at'    => now(),
        ]);

        // Notifier l'étudiant
        Notification::create([
            'user_id' => $demande->etudiant_id,
            'titre'   => 'Demande d\'encadrement rejetée',
            'message' => "Votre demande d'encadrement pour le sujet « {$demande->sujet} » a été rejetée par {$demande->encadrant->prenom} {$demande->encadrant->nom}. Motif : {$request->motif_rejet}",
            'type'    => 'rejet',
            'lu'      => false,
        ]);

        return response()->json([
            'message' => 'Demande rejetée.',
            'demande' => $this->format($demande->load(['encadrant', 'etudiant'])),
        ]);
    }

    // ── Helper ────────────────────────────────────────────────────
    private function format(DemandeEncadrement $d): array
    {
        return [
            'id'           => $d->id,
            'numero'       => $d->numero,
            'sujet'        => $d->sujet,
            'titre'        => $d->sujet, // alias
            'description'  => $d->description,
            'statut'       => $d->statut,
            'date_demande' => $d->date_demande?->format('Y-m-d'),
            'traite_at'    => $d->traite_at?->format('d/m/Y'),
            'motif_rejet'  => $d->motif_rejet,
            'doc_pdf'      => $d->doc_pdf,
            'etudiant_id'  => $d->etudiant_id,
            'etudiant'     => $d->etudiant ? $d->etudiant->prenom . ' ' . $d->etudiant->nom : null,
            'matricule'    => $d->etudiant?->matricule,
            'specialite'   => $d->etudiant?->specialite?->nom,
            'encadrant_id' => $d->encadrant_id,
            'encadrant'    => $d->encadrant ? $d->encadrant->prenom . ' ' . $d->encadrant->nom : null,
        ];
    }
}