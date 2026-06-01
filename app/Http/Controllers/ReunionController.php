<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Affectation;
use App\Models\Notification;
use App\Models\Reunion;
use App\Models\Utilisateur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReunionController extends Controller
{
    // GET /api/reunions
    public function index(Request $request): JsonResponse
    {
        $user  = Auth::user();
        $query = Reunion::with('encadrant', 'etudiant');

        if ($this->isEncadrant($user)) {
            $query->where('encadrant_id', $user->id);

            $mesEtudiantIds = Affectation::where('encadrant_id', $user->id)
                ->pluck('etudiant_id');
            $query->whereIn('etudiant_id', $mesEtudiantIds);

            if ($request->filled('etudiant_id')) {
                $etudiantId = (int) $request->etudiant_id;

                if (!$mesEtudiantIds->contains($etudiantId)) {
                    return response()->json(['message' => 'Cet étudiant ne fait pas partie de vos étudiants.'], 403);
                }

                $query->where('etudiant_id', $etudiantId);
            }

        } elseif ($user->role === 'etudiant') {
            $query->where('etudiant_id', $user->id);

        } else {
            if ($request->filled('encadrant_id')) {
                $query->where('encadrant_id', (int) $request->encadrant_id);
            }
            if ($request->filled('etudiant_id')) {
                $query->where('etudiant_id', (int) $request->etudiant_id);
            }
        }

        if ($request->filled('statut')) {
            $query->where('statut', $request->statut);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $reunions = $query->orderByDesc('date_reunion')->get()->map(fn($r) => [
            'id'                  => $r->id,
            'date_reunion'        => $r->date_reunion,
            'type'                => $r->type,
            'statut'              => $r->statut,
            'lieu'                => $r->lieu,
            'compte_rendu'        => $r->compte_rendu,
            'motif'               => $r->motif,
            'encadrant_id'        => $r->encadrant_id,
            'etudiant_id'         => $r->etudiant_id,
            'encadrant_nom'       => trim(optional($r->encadrant)->prenom . ' ' . optional($r->encadrant)->nom),
            'etudiant_nom'        => trim(optional($r->etudiant)->prenom . ' ' . optional($r->etudiant)->nom),
            'rappel_scheduled_at' => $r->rappel_scheduled_at,
            'rappel_fired'        => (bool) $r->rappel_fired,
        ]);

        return response()->json($reunions);
    }

    // POST /api/reunions
    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();

        if (!$this->isEncadrant($user)) {
            return response()->json(['message' => 'Seul un encadrant peut créer une réunion.'], 403);
        }

        $data = $request->validate([
            'etudiant_id'  => 'required|exists:utilisateurs,id',
            'date_reunion' => 'required|date',
            'type'         => 'required|in:presentiel,distanciel,mixte',
            'lieu'         => 'nullable|string|max:255',
        ]);

        $dateReunion = \Carbon\Carbon::parse($data['date_reunion']);
        if ($dateReunion->isPast()) {
            return response()->json(['message' => 'La date de la réunion ne peut pas être dans le passé.'], 422);
        }

        if ($dateReunion->isWeekend()) {
            return response()->json(['message' => 'Une réunion ne peut pas être planifiée le week-end.'], 422);
        }

        $hour = (int) $dateReunion->format('H');
        if ($hour < 8 || $hour >= 18) {
            return response()->json(['message' => 'Une réunion doit être planifiée entre 08h00 et 18h00.'], 422);
        }

        $isMyStudent = Affectation::where('encadrant_id', $user->id)
            ->where('etudiant_id', $data['etudiant_id'])
            ->exists();

        if (!$isMyStudent) {
            return response()->json(['message' => 'Cet étudiant ne fait pas partie de vos étudiants.'], 403);
        }

        $encadrantId = $user->id;
        $statut      = 'planifiee';

        $slotMinute = $dateReunion->copy()->startOfMinute();

        $doublonEtudiant = Reunion::where('encadrant_id', $encadrantId)
            ->where('etudiant_id', $data['etudiant_id'])
            ->whereIn('statut', ['planifiee', 'confirmee'])
            ->whereRaw("DATE_FORMAT(date_reunion, '%Y-%m-%d %H:%i') = ?", [$slotMinute->format('Y-m-d H:i')])
            ->exists();

        if ($doublonEtudiant) {
            return response()->json(['message' => 'Une réunion est déjà planifiée ou confirmée pour ce créneau avec cet étudiant.'], 409);
        }

        $conflit = Reunion::where('encadrant_id', $encadrantId)
            ->where('etudiant_id', '!=', $data['etudiant_id'])
            ->whereIn('statut', ['planifiee', 'confirmee'])
            ->whereRaw("DATE_FORMAT(date_reunion, '%Y-%m-%d %H:%i') = ?", [$slotMinute->format('Y-m-d H:i')])
            ->first();

        if ($conflit) {
            $autreEtudiant = Utilisateur::find($conflit->etudiant_id);
            $autreNom      = $autreEtudiant
                ? trim(($autreEtudiant->prenom ?? '') . ' ' . ($autreEtudiant->nom ?? ''))
                : 'un autre étudiant';
            return response()->json(['message' => "Vous avez déjà une réunion à ce créneau avec {$autreNom}."], 409);
        }

        try {
            $reunion = Reunion::create([
                'encadrant_id' => $encadrantId,
                'etudiant_id'  => $data['etudiant_id'],
                'date_reunion' => $data['date_reunion'],
                'type'         => $data['type'],
                'lieu'         => $data['lieu'] ?? null,
                'statut'       => $statut,
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Reunion::create failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Impossible de créer la réunion : ' . $e->getMessage()], 500);
        }

        $encadrant    = Utilisateur::find($user->id);
        $encadrantNom = trim(($encadrant->prenom ?? '') . ' ' . ($encadrant->nom ?? ''));
        $dateFormatee = \Carbon\Carbon::parse($data['date_reunion'])->format('d/m/Y à H\hi');

        Notification::create([
            'user_id' => $data['etudiant_id'],
            'message' => "Votre encadrant {$encadrantNom} vous propose une réunion ({$data['type']}) le {$dateFormatee}. Veuillez confirmer ou décliner.",
            'lu'      => false,
        ]);

        return response()->json($reunion->load('encadrant', 'etudiant'), 201);
    }

    // GET /api/reunions/{reunion}
    public function show(Reunion $reunion): JsonResponse
    {
        $this->authorizeAccess($reunion);
        return response()->json($reunion->load('encadrant', 'etudiant'));
    }

    // PUT /api/reunions/{reunion}
    public function update(Request $request, Reunion $reunion): JsonResponse
    {
        $user = Auth::user();

        if (!$this->isEncadrant($user) || $reunion->encadrant_id !== $user->id) {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }

        $data = $request->validate([
            'date_reunion' => 'sometimes|date',
            'type'         => 'sometimes|in:presentiel,distanciel,mixte',
            'lieu'         => 'nullable|string|max:255',
        ]);

        if (isset($data['date_reunion'])) {
            $nouvelleDate = \Carbon\Carbon::parse($data['date_reunion']);

            if ($nouvelleDate->isPast()) {
                return response()->json(['message' => 'La nouvelle date ne peut pas être dans le passé.'], 422);
            }

            if ($nouvelleDate->isWeekend()) {
                return response()->json(['message' => 'Une réunion ne peut pas être planifiée le week-end.'], 422);
            }

            $hour = (int) $nouvelleDate->format('H');
            if ($hour < 8 || $hour >= 18) {
                return response()->json(['message' => 'Une réunion doit être planifiée entre 08h00 et 18h00.'], 422);
            }

            $slotMinute = $nouvelleDate->copy()->startOfMinute();
            $conflit = Reunion::where('encadrant_id', $reunion->encadrant_id)
                ->where('id', '!=', $reunion->id)
                ->whereIn('statut', ['planifiee', 'confirmee'])
                ->whereRaw("DATE_FORMAT(date_reunion, '%Y-%m-%d %H:%i') = ?", [$slotMinute->format('Y-m-d H:i')])
                ->first();

            if ($conflit) {
                $autreEtudiant = Utilisateur::find($conflit->etudiant_id);
                $autreNom      = $autreEtudiant
                    ? trim(($autreEtudiant->prenom ?? '') . ' ' . ($autreEtudiant->nom ?? ''))
                    : 'un autre étudiant';
                return response()->json(['message' => "Ce créneau est déjà occupé par une réunion avec {$autreNom}."], 409);
            }
        }

        $reunion->update($data);

        if (isset($data['date_reunion']) || isset($data['lieu']) || isset($data['type'])) {
            $encadrant    = Utilisateur::find($reunion->encadrant_id);
            $encadrantNom = trim(($encadrant->prenom ?? '') . ' ' . ($encadrant->nom ?? ''));
            $dateFormatee = \Carbon\Carbon::parse($reunion->fresh()->date_reunion)->format('d/m/Y à H\hi');
            $lieuMsg      = $reunion->fresh()->lieu ? " — Lieu : {$reunion->fresh()->lieu}" : '';

            Notification::create([
                'user_id' => $reunion->etudiant_id,
                'message' => "Votre encadrant {$encadrantNom} a modifié votre réunion. Nouveau créneau : {$dateFormatee}{$lieuMsg}.",
                'lu'      => false,
            ]);
        }

        return response()->json($reunion);
    }

    // DELETE /api/reunions/{reunion}
    public function destroy(Reunion $reunion): JsonResponse
    {
        $user = Auth::user();

        if ($this->isEncadrant($user) && $reunion->encadrant_id !== $user->id) {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }

        if ($user->role === 'etudiant' && $reunion->etudiant_id !== $user->id) {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }

        if ($reunion->statut !== 'annulee') {
            return response()->json([
                'message' => 'Seules les réunions annulées peuvent être supprimées.',
            ], 422);
        }

        $reunion->delete();
        return response()->json(['message' => 'Réunion supprimée.']);
    }

    // POST /api/reunions/{reunion}/confirmer
    public function confirmer(Reunion $reunion): JsonResponse
    {
        $user = Auth::user();

        if ($reunion->etudiant_id !== $user->id) {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }

        $reunion->update(['statut' => 'confirmee']);

        $etudiant     = Utilisateur::find($reunion->etudiant_id);
        $etudiantNom  = trim(($etudiant->prenom ?? '') . ' ' . ($etudiant->nom ?? ''));
        $dateFormatee = \Carbon\Carbon::parse($reunion->date_reunion)->format('d/m/Y à H\hi');

        Notification::create([
            'user_id' => $reunion->encadrant_id,
            'message' => "{$etudiantNom} a confirmé la réunion du {$dateFormatee}.",
            'lu'      => false,
        ]);

        return response()->json($reunion);
    }

    // POST /api/reunions/{reunion}/annuler
    public function annuler(Request $request, Reunion $reunion): JsonResponse
    {
        $user = Auth::user();

        if ($reunion->encadrant_id !== $user->id && $reunion->etudiant_id !== $user->id) {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }

        $data = $request->validate([
            'motif' => 'nullable|string',
        ]);

        $reunion->update(['statut' => 'annulee', 'motif' => $data['motif'] ?? null]);

        $dateFormatee = \Carbon\Carbon::parse($reunion->date_reunion)->format('d/m/Y à H\hi');
        $motifMsg     = !empty($data['motif']) ? " Motif : {$data['motif']}" : '';

        if ($user->role === 'etudiant') {
            $etudiant    = Utilisateur::find($reunion->etudiant_id);
            $etudiantNom = trim(($etudiant->prenom ?? '') . ' ' . ($etudiant->nom ?? ''));

            Notification::create([
                'user_id' => $reunion->encadrant_id,
                'message' => "{$etudiantNom} a décliné la réunion du {$dateFormatee}.{$motifMsg}",
                'lu'      => false,
            ]);
        } else {
            $encadrant    = Utilisateur::find($reunion->encadrant_id);
            $encadrantNom = trim(($encadrant->prenom ?? '') . ' ' . ($encadrant->nom ?? ''));

            Notification::create([
                'user_id' => $reunion->etudiant_id,
                'message' => "Votre encadrant {$encadrantNom} a annulé la réunion du {$dateFormatee}.{$motifMsg}",
                'lu'      => false,
            ]);
        }

        return response()->json($reunion);
    }

    // POST /api/reunions/{reunion}/compte-rendu
    public function compteRendu(Request $request, Reunion $reunion): JsonResponse
    {
        $user = Auth::user();

        if ($reunion->encadrant_id !== $user->id) {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }

        $data = $request->validate([
            'compte_rendu' => 'required|string',
        ]);

        $reunion->update([
            'compte_rendu' => $data['compte_rendu'],
            'statut'       => 'effectuee',
        ]);

        Notification::create([
            'user_id' => $reunion->etudiant_id,
            'message' => 'Le compte-rendu de votre réunion a été rédigé par votre encadrant.',
            'lu'      => false,
        ]);

        return response()->json($reunion);
    }

    // POST /api/reunions/{reunion}/rappel
    // Stores rappel_scheduled_at on the reunion row.
    // The actual reminder is sent by: php artisan reunions:send-rappels  (runs every minute via scheduler)
    public function rappel(Request $request, Reunion $reunion): JsonResponse
    {
        $user = Auth::user();

        if ($reunion->etudiant_id !== $user->id) {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }

        if ($reunion->statut !== 'confirmee') {
            return response()->json(['message' => 'Le rappel ne peut être activé que pour une réunion confirmée.'], 422);
        }

        $data = $request->validate([
            'scheduled_at' => 'required|date|after:now',
            'delai_jours'  => 'required|integer|in:0,1,2',
            'heure'        => 'required|string',
        ]);

        // Store the scheduled time so the Artisan command can fire it.
        $reunion->update([
            'rappel_scheduled_at' => \Carbon\Carbon::parse($data['scheduled_at']),
            'rappel_fired'        => false,
        ]);

        $encadrant    = Utilisateur::find($reunion->encadrant_id);
        $encadrantNom = trim(($encadrant->prenom ?? '') . ' ' . ($encadrant->nom ?? ''));
        $dateReunion  = \Carbon\Carbon::parse($reunion->date_reunion)->format('d/m/Y à H\hi');
        $scheduledFmt = \Carbon\Carbon::parse($data['scheduled_at'])->format('d/m/Y à H\hi');
        $delaiLabel   = $data['delai_jours'] === 0 ? 'le jour même' : "J-{$data['delai_jours']}";

        // Immediate confirmation to the student only.
        Notification::create([
            'user_id' => $reunion->etudiant_id,
            'message' => "Rappel activé ({$delaiLabel} à {$data['heure']}) pour votre réunion avec {$encadrantNom} le {$dateReunion}. Vous serez notifié le {$scheduledFmt}.",
            'lu'      => false,
        ]);

        return response()->json(['message' => 'Rappel configuré.', 'scheduled_at' => $data['scheduled_at']]);
    }

    // POST /api/reunions/{reunion}/rappel/annuler
    public function annulerRappel(Reunion $reunion): JsonResponse
    {
        $user = Auth::user();

        if ($reunion->etudiant_id !== $user->id) {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }

        $reunion->update([
            'rappel_scheduled_at' => null,
            'rappel_fired'        => false,
        ]);

        return response()->json(['message' => 'Rappel annulé.']);
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function isEncadrant($user): bool
    {
        return in_array($user->role, ['encadrant', 'chef']);
    }

    private function authorizeAccess(Reunion $reunion): void
    {
        $user = Auth::user();

        if ($this->isEncadrant($user) && $reunion->encadrant_id !== $user->id) {
            abort(403, 'Non autorisé.');
        }
        if ($user->role === 'etudiant' && $reunion->etudiant_id !== $user->id) {
            abort(403, 'Non autorisé.');
        }
    }
}