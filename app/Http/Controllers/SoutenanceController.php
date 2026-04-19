<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Affectation;
use App\Models\Jury;
use App\Models\Notification;
use App\Models\SeanceSoutenance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SoutenanceController extends Controller
{
    // GET /api/soutenances
    public function index(): JsonResponse
    {
        $seances = SeanceSoutenance::with([
            'jury.affectation.etudiant',
            'jury.affectation.encadrant',
            'jury.membres.enseignant',
        ])->orderBy('date_seance')->get()->map(function ($s) {
            $jury = $s->jury;
            $aff  = optional($jury)->affectation;

            return [
                'id'             => $s->id,
                'jury_id'        => $s->jury_id,
                'date'           => $s->date_seance->format('Y-m-d'),
                'heure_debut'    => $s->date_seance->format('H:i'),
                'heure_fin'      => $s->date_seance->addHour()->format('H:i'),
                'salle'          => $s->salle,
                'statut'         => $s->statut,
                'projet_titre'   => optional($aff)->titre_projet,
                'etudiant_nom'   => optional(optional($aff)->etudiant)->nom_complet,
                'membres'        => optional($jury)->membres->map(fn($m) => [
                    'id'       => $m->id,
                    'nom'      => optional($m->enseignant)->nom_complet,
                    'fonction' => $m->fonction,
                ]),
            ];
        });

        return response()->json($seances);
    }

    // POST /api/soutenances
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'jury_id'     => 'required|exists:jurys,id',
            'date_seance' => 'required|date',
            'salle'       => 'required|string|max:100',
        ]);

        $seance = SeanceSoutenance::create([
            'jury_id'     => $data['jury_id'],
            'date_seance' => $data['date_seance'],
            'salle'       => $data['salle'],
            'statut'      => 'planifiee',
        ]);

        // Notifier les membres du jury + l'étudiant
        $jury = Jury::with('affectation', 'membres')->find($data['jury_id']);
        $dateFormatee = \Carbon\Carbon::parse($data['date_seance'])->format('d/m/Y à H:i');
        $message = "Soutenance planifiée le {$dateFormatee} en salle {$data['salle']}.";

        foreach ($jury->membres as $membre) {
            Notification::create([
                'user_id'    => $membre->enseignant_id,
                'message'    => $message,
                'created_at' => now(),
            ]);
        }

        if (optional($jury->affectation)->etudiant_id) {
            Notification::create([
                'user_id'    => $jury->affectation->etudiant_id,
                'message'    => $message,
                'created_at' => now(),
            ]);
        }

        return response()->json($seance->load('jury'), 201);
    }

    // GET /api/soutenances/{seance}
    public function show(SeanceSoutenance $seance): JsonResponse
    {
        return response()->json($seance->load(
            'jury.affectation.etudiant',
            'jury.membres.enseignant'
        ));
    }

    // PUT /api/soutenances/{seance}
    public function update(Request $request, SeanceSoutenance $seance): JsonResponse
    {
        $data = $request->validate([
            'date_seance' => 'sometimes|date',
            'salle'       => 'sometimes|string|max:100',
            'statut'      => 'sometimes|in:planifiee,terminee,annulee',
        ]);

        $seance->update($data);

        return response()->json($seance);
    }

    // DELETE /api/soutenances/{seance}
    public function destroy(SeanceSoutenance $seance): JsonResponse
    {
        $seance->delete();
        return response()->json(['message' => 'Séance supprimée.']);
    }

    // POST /api/soutenances/{seance}/terminer
    public function terminer(SeanceSoutenance $seance): JsonResponse
    {
        $seance->update(['statut' => 'terminee']);
        return response()->json($seance);
    }

    // POST /api/soutenances/{seance}/annuler
    public function annuler(SeanceSoutenance $seance): JsonResponse
    {
        $seance->update(['statut' => 'annulee']);
        return response()->json($seance);
    }

    // ═══════════════════════════════════════════════════════════════
    // MÉTHODES AJOUTÉES POUR LE SPRINT 3
    // ═══════════════════════════════════════════════════════════════

    // GET /api/soutenances/projets-disponibles
    public function projetsDisponibles(): JsonResponse
    {
        $projets = Affectation::whereDoesntHave('jury')
            ->with('etudiant')
            ->get()
            ->map(function ($projet) {
                return [
                    'id'       => $projet->id,
                    'titre'    => $projet->titre_projet ?? 'Projet #' . $projet->id,
                    'etudiant' => $projet->etudiant?->nom_complet ?? 'Étudiant',
                ];
            });

        return response()->json($projets);
    }

    // GET /api/soutenances/plans-proposes
    public function plansProposes(): JsonResponse
    {
        // Retourner les plans proposés par les membres de jury
        // À adapter selon votre structure de données
        return response()->json([]);
    }

    // PUT /api/soutenances/plans/{plan}/valider
    public function validerPlan($plan): JsonResponse
    {
        // Logique pour valider un plan
        return response()->json(['message' => 'Plan validé avec succès.']);
    }

    // PUT /api/soutenances/plans/{plan}/rejeter
    public function rejeterPlan($plan): JsonResponse
    {
        // Logique pour rejeter un plan
        return response()->json(['message' => 'Plan rejeté.']);
    }

    // PUT /api/soutenances/{seance}/affecter
    public function affecterProjet(Request $request, $seance): JsonResponse
    {
        $data = $request->validate([
            'projet_id' => 'required|exists:affectations,id',
        ]);

        $seanceModel = SeanceSoutenance::findOrFail($seance);
        $jury = $seanceModel->jury;
        
        if ($jury) {
            $jury->update(['affectation_id' => $data['projet_id']]);
        }

        return response()->json(['message' => 'Projet affecté à la session.']);
    }

    // POST /api/soutenances/publier-calendrier
    public function publierCalendrier(): JsonResponse
    {
        // Logique pour publier le calendrier et notifier tous les participants
        $seances = SeanceSoutenance::with(['jury.affectation.etudiant', 'jury.membres.enseignant'])
            ->where('statut', 'planifiee')
            ->get();

        foreach ($seances as $seance) {
            $dateFormatee = $seance->date_seance->format('d/m/Y à H:i');
            $message = "Soutenance programmée le {$dateFormatee} en salle {$seance->salle}.";
            
            // Notifier l'étudiant
            if ($seance->jury?->affectation?->etudiant_id) {
                Notification::create([
                    'user_id'    => $seance->jury->affectation->etudiant_id,
                    'message'    => $message,
                    'created_at' => now(),
                ]);
            }
            
            // Notifier les membres du jury
            foreach ($seance->jury?->membres ?? [] as $membre) {
                Notification::create([
                    'user_id'    => $membre->enseignant_id,
                    'message'    => $message,
                    'created_at' => now(),
                ]);
            }
        }

        return response()->json(['message' => 'Calendrier publié avec succès.']);
    }
}