<?php

namespace App\Http\Controllers;

use App\Models\JuryMembrePfe;
use App\Models\Notification;
use App\Models\PlanSoutenance;
use App\Models\Soutenance;
use App\Models\Utilisateur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SoutenancePlanificationController
 *
 * Handles soutenance scheduling under the new flat-role schema.
 *
 * New schema:
 *   jury_membres_pfe : id | chef_id | projet_id | encadrant_id | president_id | examinateur_id | publie
 *   plans_soutenance : id | jury_id | proposant_id | fonction | date | heure_debut | heure_fin | salle | statut | motif_rejet | date_traitement
 *   soutenances      : id | jury_id | projet_id | date_soutenance | heure_debut | heure_fin | salle | statut | calendrier_publie
 *
 * Soutenance lifecycle (soutenances.statut):
 *   en_attente → created when plan validated, not yet published
 *   publie     → chef published the calendar
 *   termine    → jury/president submitted the evaluation
 *
 * Routes:
 *   GET  /api/soutenances/salles-occupees         → sallesOccupees
 *   GET  /api/soutenances/enseignants-occupes     → enseignantsOccupes
 *   PUT  /api/jurys-pfe/{juryPfe}                 → update  (salle only — date+heure locked)
 *   POST /api/jurys-pfe/publier-calendrier        → publierCalendrier
 *   GET  /api/plans-soutenance                    → indexPlans
 *   POST /api/plans-soutenance                    → storePlan
 *   PUT  /api/plans-soutenance/{plan}/valider     → validerPlan
 *   PUT  /api/plans-soutenance/{plan}/rejeter     → rejeterPlan
 *   DELETE /api/plans-soutenance/{plan}           → destroyPlan
 *   DELETE /api/plans-soutenance/{plan}/chef      → destroyPlanChef
 *   PUT  /api/soutenances/{soutenance}/terminer   → terminerSoutenance
 */
class SoutenancePlanificationController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // Room / teacher conflict helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET /api/soutenances/salles-occupees
     * Returns rooms booked at the given slot across ALL departments.
     */
    public function sallesOccupees(Request $request): JsonResponse
    {
        $request->validate([
            'date'        => 'required|date',
            'heure_debut' => 'required|date_format:H:i',
            'heure_fin'   => 'required|date_format:H:i',
            'exclude_id'  => 'nullable|integer',
        ]);

        $date       = $request->date;
        $heureDebut = $request->heure_debut;
        $heureFin   = $request->heure_fin;
        $excludeId  = $request->exclude_id;

        $toMin = fn(string $t): int => ((int) explode(':', $t)[0]) * 60 + (int) (explode(':', $t)[1] ?? 0);
        $newStart = $toMin($heureDebut);
        $newEnd   = $toMin($heureFin);

        $authDeptId = Auth::user()?->specialite_id;

        $conflicting = Soutenance::with('jury.projet.etudiant')
            ->where('date_soutenance', $date)
            ->whereNotNull('salle')
            ->whereNotNull('heure_debut')
            ->whereNotNull('heure_fin')
            ->when($excludeId, fn($q) => $q->where('id', '!=', $excludeId))
            ->get()
            ->filter(fn($s) => $toMin($s->heure_debut) < $newEnd && $toMin($s->heure_fin) > $newStart);

        $seen   = [];
        $salles = [];
        foreach ($conflicting as $s) {
            if (!$s->salle || isset($seen[$s->salle])) continue;
            $seen[$s->salle]  = true;
            $etudiantDeptId   = $s->jury?->projet?->etudiant?->specialite_id;
            $salles[] = [
                'salle'     => $s->salle,
                'same_dept' => $authDeptId && $etudiantDeptId && $authDeptId === $etudiantDeptId,
            ];
        }

        return response()->json(array_values($salles));
    }

    /**
     * GET /api/soutenances/enseignants-occupes
     * Returns teachers already assigned to a soutenance at the given slot.
     * Uses the new flat-role columns on jury_membres_pfe instead of iterating membre rows.
     */
    public function enseignantsOccupes(Request $request): JsonResponse
    {
        $request->validate([
            'date'        => 'required|date',
            'heure_debut' => 'required|date_format:H:i',
            'heure_fin'   => 'required|date_format:H:i',
            'exclude_id'  => 'nullable|integer',
        ]);

        $date       = $request->date;
        $heureDebut = $request->heure_debut;
        $heureFin   = $request->heure_fin;
        $excludeId  = $request->exclude_id;

        $toMin    = fn(string $t): int => ((int) explode(':', $t)[0]) * 60 + (int) (explode(':', $t)[1] ?? 0);
        $newStart = $toMin($heureDebut);
        $newEnd   = $toMin($heureFin);

        $soutenances = Soutenance::with([
            'jury.encadrant',
            'jury.president',
            'jury.examinateur',
            'jury.projet.etudiant',
        ])
            ->where('date_soutenance', $date)
            ->whereNotNull('heure_debut')
            ->whereNotNull('heure_fin')
            ->when($excludeId, fn($q) => $q->where('id', '!=', $excludeId))
            ->get()
            ->filter(fn($s) => $toMin($s->heure_debut) < $newEnd && $toMin($s->heure_fin) > $newStart);

        $authDeptId = Auth::user()?->specialite_id;
        $result     = [];

        foreach ($soutenances as $soutenance) {
            $jury           = $soutenance->jury;
            $etudiant       = $jury?->projet?->etudiant;
            $etudiantDeptId = $etudiant?->specialite_id;
            $sameDept       = $authDeptId && $etudiantDeptId && $authDeptId === $etudiantDeptId;
            $projetTitre    = $jury?->projet?->titre ?? ('Soutenance #' . $soutenance->id);
            $etudiantNom    = $etudiant ? trim(($etudiant->prenom ?? '') . ' ' . ($etudiant->nom ?? '')) : null;

            // Flatten the 3 role columns into individual entries
            $roles = [
                'encadrant'   => $jury?->encadrant_id   ? $jury->encadrant   : null,
                'president'   => $jury?->president_id   ? $jury->president   : null,
                'examinateur' => $jury?->examinateur_id ? $jury->examinateur : null,
            ];

            foreach ($roles as $role => $enseignant) {
                if (!$enseignant) continue;
                $result[] = [
                    'enseignant_id' => $enseignant->id,
                    'nom'           => $enseignant->nom    ?? '',
                    'prenom'        => $enseignant->prenom ?? '',
                    'role'          => $role,
                    'etudiant_nom'  => $etudiantNom,
                    'projet_titre'  => $projetTitre,
                    'heure_debut'   => substr($soutenance->heure_debut, 0, 5),
                    'heure_fin'     => substr($soutenance->heure_fin,   0, 5),
                    'soutenance_id' => $soutenance->id,
                    'same_dept'     => $sameDept,
                ];
            }
        }

        return response()->json($result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Session management (chef de département)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * PUT /api/jurys-pfe/{juryPfe}
     *
     * The {juryPfe} param resolves to a Soutenance (the session record), not JuryMembrePfe.
     * Only salle is editable after a session is created from a validated plan.
     * date_soutenance + heure_debut + heure_fin are locked by the validated plan.
     */
    public function update(Request $request, \App\Models\JuryMembrePfe $juryPfe): JsonResponse
    {
        $soutenance = $juryPfe->soutenance;
        $isCreation = !$soutenance;

        // For manual creation all scheduling fields are required.
        // For editing an existing session only salle can be changed (date+heure locked).
        if ($isCreation) {
            $data = $request->validate([
                'date_soutenance' => 'required|date',
                'heure_debut'     => 'required|date_format:H:i',
                'heure_fin'       => 'required|date_format:H:i|after:heure_debut',
                'salle'           => 'sometimes|nullable|string|max:100',
            ]);
        } else {
            $data = $request->validate([
                'date_soutenance' => 'sometimes|nullable|date',
                'heure_debut'     => 'sometimes|nullable|date_format:H:i',
                'heure_fin'       => 'sometimes|nullable|date_format:H:i',
                'salle'           => 'sometimes|nullable|string|max:100',
                'statut'          => 'sometimes|in:en_attente,publie,termine',
            ]);
        }

        $effectiveSalle      = $data['salle']           ?? ($soutenance?->salle);
        $effectiveDate       = $data['date_soutenance'] ?? ($soutenance?->date_soutenance);
        $effectiveHeureDebut = $data['heure_debut']     ?? ($soutenance?->heure_debut);
        $effectiveHeureFin   = $data['heure_fin']       ?? ($soutenance?->heure_fin);

        if ($effectiveSalle && $effectiveDate && $effectiveHeureDebut && $effectiveHeureFin) {
            $toMin    = fn(string $t): int => (int) substr($t, 0, 2) * 60 + (int) substr($t, 3, 2);
            $newStart = $toMin($effectiveHeureDebut);
            $newEnd   = $toMin($effectiveHeureFin);

            if ($newEnd <= $newStart) {
                return response()->json(['message' => "L'heure de fin doit être après l'heure de début."], 422);
            }

            $conflict = Soutenance::where('salle', $effectiveSalle)
                ->where('date_soutenance', $effectiveDate)
                ->when(!$isCreation, fn($q) => $q->where('id', '!=', $soutenance->id))
                ->whereNotNull('heure_debut')
                ->whereNotNull('heure_fin')
                ->get()
                ->first(fn($s) =>
                    $toMin($s->heure_debut) < $newEnd && $toMin($s->heure_fin) > $newStart
                );

            if ($conflict) {
                return response()->json([
                    'message' => "La salle \"{$effectiveSalle}\" est déjà réservée à ce créneau (" .
                        substr($conflict->heure_debut, 0, 5) . '–' . substr($conflict->heure_fin, 0, 5) . ').',
                ], 422);
            }
        }

        if ($isCreation) {
            // Manual creation: create the soutenance row directly (no plan required)
            $soutenance = Soutenance::create([
                'jury_id'         => $juryPfe->id,
                'projet_id'       => $juryPfe->projet_id,
                'date_soutenance' => $effectiveDate,
                'heure_debut'     => $effectiveHeureDebut,
                'heure_fin'       => $effectiveHeureFin,
                'salle'           => $effectiveSalle,
                'statut'          => 'en_attente',
            ]);
        } else {
            $soutenance->update($data);
        }

        return response()->json(
            $soutenance->fresh(['jury.encadrant', 'jury.president', 'jury.examinateur', 'jury.projet.etudiant'])
        );
    }

    /**
     * POST /api/jurys-pfe/publier-calendrier
     *
     * Publishes all 'en_attente' sessions → statut='publie' + calendrier_publie=true.
     *
     * Notification strategy:
     *   - Sessions created via a validated plan → already notified at validerPlan time.
     *     Only send a short "your soutenance is now officially published" message.
     *   - Sessions created manually by the chef → first notification they receive,
     *     so send the full slot details.
     */
    public function publierCalendrier(): JsonResponse
    {
        $soutenances = Soutenance::with([
            'jury.encadrant',
            'jury.president',
            'jury.examinateur',
            'jury.projet.etudiant',
        ])
            ->where('statut', 'en_attente')
            ->whereNotNull('date_soutenance')
            ->get();

        // Pre-load jury_ids that already have an approved plan so we know which
        // sessions were created via plan validation vs manually by the chef.
        $approvedJuryIds = \App\Models\PlanSoutenance::where('statut', 'approuve')
            ->pluck('jury_id')
            ->flip()
            ->toArray();

        foreach ($soutenances as $soutenance) {
            $jury        = $soutenance->jury;
            $projet      = $jury?->projet;
            $etudiant    = $projet?->etudiant;
            $titre       = $projet?->titre ?? ('Soutenance #' . $soutenance->id);
            $date        = $soutenance->date_soutenance;
            $debut       = substr($soutenance->heure_debut ?? '', 0, 5);
            $fin         = substr($soutenance->heure_fin   ?? '', 0, 5);
            $salle       = $soutenance->salle ?? '—';
            $fromPlan    = isset($approvedJuryIds[$jury?->id]);

            $nomEtudiant = $etudiant
                ? trim(($etudiant->prenom ?? '') . ' ' . ($etudiant->nom ?? ''))
                : 'un étudiant';

            if ($fromPlan) {
                // Session came from a validated plan — members were already notified
                // at validation time. Just confirm publication to the student and jury.
                $pubMsg = "Le calendrier des soutenances a été publié. "
                    . "Votre soutenance pour « {$titre} » est confirmée le {$date} "
                    . "de {$debut} à {$fin} en salle {$salle}.";

                if ($etudiant) {
                    Notification::create(['user_id' => $etudiant->id, 'message' => $pubMsg]);
                }
                foreach (array_filter([$jury?->encadrant_id, $jury?->president_id, $jury?->examinateur_id]) as $memberId) {
                    Notification::create(['user_id' => $memberId, 'message' => $pubMsg]);
                }
            } else {
                // Manually created session — this is the first notification these people receive.
                if ($etudiant) {
                    Notification::create([
                        'user_id' => $etudiant->id,
                        'message' => "Votre soutenance pour le projet « {$titre} » est planifiée le {$date} de {$debut} à {$fin} en salle {$salle}.",
                    ]);
                }

                $roles = [
                    'Encadrant'        => $jury?->encadrant_id,
                    'Président de jury' => $jury?->president_id,
                    'Examinateur'      => $jury?->examinateur_id,
                ];
                foreach ($roles as $label => $memberId) {
                    if (!$memberId) continue;
                    Notification::create([
                        'user_id' => $memberId,
                        'message' => "{$label} — Soutenance de {$nomEtudiant} (« {$titre} ») planifiée le {$date} de {$debut} à {$fin} en salle {$salle}.",
                    ]);
                }
            }
        }

        Soutenance::where('statut', 'en_attente')
            ->whereNotNull('date_soutenance')
            ->update([
                'statut'            => 'publie',
                'calendrier_publie' => true,
            ]);

        return response()->json(['message' => 'Calendrier publié.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Plan management
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET /api/plans-soutenance
     *
     * Chef: returns plans linked to their department (via jury.projet.etudiant.specialite_id)
     * Non-chef: returns only plans submitted by the authenticated user
     */
    public function indexPlans(): JsonResponse
    {
        $user  = Auth::user();
        $query = PlanSoutenance::with(['proposant', 'jury.projet.etudiant'])->latest();

        if (in_array($user?->role, ['chef_departement', 'chef', 'admin'])) {
            if ($user->specialite_id) {
                $query->whereHas('jury.projet.etudiant', fn($q) =>
                    $q->where('specialite_id', $user->specialite_id)
                );
            }
        } else {
            // Proposants only see their own plans
            $query->where('proposant_id', $user->id);
        }

        return response()->json($query->get()->map(fn($p) => $this->formatPlan($p)));
    }

    /**
     * POST /api/plans-soutenance
     *
     * A published jury member proposes a defence slot.
     */
    public function storePlan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'proposant_id' => 'required|exists:utilisateurs,id',
            'jury_id'      => 'required|exists:jury_membres_pfe,id',
            'fonction'     => 'required|in:encadrant,president,examinateur',
            'date'         => 'required|date',
            'heure_debut'  => 'required|date_format:H:i',
            'heure_fin'    => 'required|date_format:H:i|after:heure_debut',
            'salle'        => 'required|string|max:100',
        ]);

        // Guard: jury must be published
        $jury = JuryMembrePfe::findOrFail($data['jury_id']);
        if (!$jury->publie) {
            return response()->json(['message' => 'Ce jury n\'est pas encore publié.'], 422);
        }

        // Guard: proposant must actually be a member of this jury
        $uid = (int) $data['proposant_id'];
        if (!in_array($uid, array_filter([
            $jury->encadrant_id,
            $jury->president_id,
            $jury->examinateur_id,
        ]))) {
            return response()->json(['message' => 'Vous n\'êtes pas membre de ce jury.'], 403);
        }

        $plan = PlanSoutenance::create([
            'jury_id'      => $data['jury_id'],
            'proposant_id' => $data['proposant_id'],
            'fonction'     => $data['fonction'],
            'statut'       => 'en_attente',
            'date'         => $data['date'],
            'heure_debut'  => $data['heure_debut'],
            'heure_fin'    => $data['heure_fin'],
            'salle'        => $data['salle'],
        ]);

        // Notify chef(s)
        $fonctionLabel = match ($data['fonction']) {
            'president'   => 'Président de jury',
            'examinateur' => 'Examinateur',
            default       => 'Encadrant',
        };
        $chefs = Utilisateur::whereIn('role', ['chef_departement', 'chef'])->get();
        foreach ($chefs as $chef) {
            Notification::create([
                'user_id' => $chef->id,
                'message' => "Un nouveau plan de soutenance a été proposé par un {$fonctionLabel}.",
            ]);
        }

        return response()->json($this->formatPlan($plan->load(['jury.projet', 'proposant'])), 201);
    }

    /**
     * PUT /api/plans-soutenance/{plan}/valider
     *
     * Approves the plan.
     * Creates a soutenances row (statut = 'en_attente') if one doesn't exist yet.
     * date + heure are LOCKED from the plan; only salle can be overridden by chef.
     */
    public function validerPlan(Request $request, PlanSoutenance $plan): JsonResponse
    {
        if ($plan->statut !== 'en_attente') {
            return response()->json(['message' => 'Ce plan a déjà été traité.'], 422);
        }

        $overrides = $request->validate([
            'heure_debut' => 'nullable|date_format:H:i',
            'heure_fin'   => 'nullable|date_format:H:i',
            'salle'       => 'nullable|string|max:100',
        ]);

        $heureDebut = $overrides['heure_debut'] ?? $plan->heure_debut;
        $heureFin   = $overrides['heure_fin']   ?? $plan->heure_fin;
        $salle      = $overrides['salle']        ?? $plan->salle;

        $plan->update([
            'statut'          => 'approuve',
            'date_traitement' => now(),
        ]);

        DB::beginTransaction();
        try {
            $jury = $plan->jury()->with('projet.etudiant')->first();

            // Create the soutenance session (one per jury_id — unique)
            $soutenance = Soutenance::updateOrCreate(
                ['jury_id' => $plan->jury_id],
                [
                    'projet_id'       => $jury?->projet_id,
                    'date_soutenance' => $plan->date,
                    'heure_debut'     => $heureDebut,
                    'heure_fin'       => $heureFin,
                    'salle'           => $salle,
                    'statut'          => 'en_attente',
                ]
            );

            // Notify proposant
            Notification::create([
                'user_id' => $plan->proposant_id,
                'message' => 'Votre plan de soutenance a été validé par le chef de département.',
            ]);

            // Notify student
            $etudiant = $jury?->projet?->etudiant;
            $titre    = $jury?->projet?->titre ?? ('Soutenance #' . $soutenance->id);
            if ($etudiant) {
                Notification::create([
                    'user_id' => $etudiant->id,
                    'message' => "Votre soutenance pour le projet « {$titre} » a été planifiée le {$plan->date} "
                        . "de " . substr($heureDebut, 0, 5) . " à " . substr($heureFin, 0, 5) . " en salle {$salle}.",
                ]);
            }

            // Notify other jury members (not the proposant)
            $nomEtudiant = $etudiant ? trim(($etudiant->prenom ?? '') . ' ' . ($etudiant->nom ?? '')) : 'un étudiant';
            $roles = [
                'encadrant'   => $jury?->encadrant_id,
                'president'   => $jury?->president_id,
                'examinateur' => $jury?->examinateur_id,
            ];
            foreach ($roles as $roleLabel => $memberId) {
                if (!$memberId || $memberId === $plan->proposant_id) continue;
                $label = match ($roleLabel) {
                    'president'   => 'Président de jury',
                    'examinateur' => 'Examinateur',
                    default       => 'Encadrant',
                };
                Notification::create([
                    'user_id' => $memberId,
                    'message' => "{$label} — Soutenance de {$nomEtudiant} (« {$titre} ») planifiée le {$plan->date} "
                        . "de " . substr($heureDebut, 0, 5) . " à " . substr($heureFin, 0, 5) . " en salle {$salle}.",
                ]);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('validerPlan error: ' . $e->getMessage());
            return response()->json(['message' => 'Erreur lors de la validation : ' . $e->getMessage()], 500);
        }

        return response()->json([
            'message' => 'Plan validé.',
            'plan'    => $this->formatPlan($plan->fresh()),
        ]);
    }

    /**
     * PUT /api/plans-soutenance/{plan}/rejeter
     */
    public function rejeterPlan(Request $request, PlanSoutenance $plan): JsonResponse
    {
        $validated = $request->validate(['motif' => 'nullable|string|max:1000']);
        $motif     = $validated['motif'] ?? null;

        $plan->update([
            'statut'          => 'rejete',
            'motif_rejet'     => $motif,
            'date_traitement' => now(),
        ]);

        $message = 'Votre plan de soutenance a été rejeté par le chef de département.';
        if ($motif) $message .= ' Motif : ' . $motif;

        Notification::create(['user_id' => $plan->proposant_id, 'message' => $message]);

        return response()->json(['message' => 'Plan rejeté.', 'plan' => $this->formatPlan($plan->fresh())]);
    }

    /**
     * DELETE /api/plans-soutenance/{plan}
     * Proposant deletes their own rejected plan.
     */
    public function destroyPlan(PlanSoutenance $plan): JsonResponse
    {
        if ($plan->proposant_id !== Auth::id()) {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }
        if ($plan->statut !== 'rejete') {
            return response()->json(['message' => 'Seuls les plans rejetés peuvent être supprimés.'], 422);
        }
        $plan->delete();
        return response()->json(['message' => 'Plan supprimé.']);
    }

    /**
     * DELETE /api/plans-soutenance/{plan}/chef
     * Chef deletes any rejected plan.
     */
    public function destroyPlanChef(PlanSoutenance $plan): JsonResponse
    {
        $user = Auth::user();
        if (!in_array($user?->role, ['chef_departement', 'chef', 'admin'])) {
            return response()->json(['message' => 'Non autorisé — rôle chef requis.'], 403);
        }
        if ($plan->statut !== 'rejete') {
            return response()->json(['message' => 'Seuls les plans rejetés peuvent être supprimés.'], 422);
        }
        $plan->delete();
        return response()->json(['message' => 'Plan supprimé.']);
    }

    /**
     * PUT /api/soutenances/{soutenance}/terminer
     * Transitions soutenance statut → 'termine' when evaluation is submitted.
     */
    public function terminerSoutenance(Request $request, Soutenance $soutenance): JsonResponse
    {
        if (!in_array($soutenance->statut, ['en_attente', 'publie'])) {
            return response()->json([
                'message' => "La soutenance est déjà «{$soutenance->statut}» et ne peut pas être marquée comme terminée.",
            ], 422);
        }

        $soutenance->update(['statut' => 'termine']);

        $etudiant = $soutenance->jury?->projet?->etudiant;
        $titre    = $soutenance->jury?->projet?->titre ?? ('Soutenance #' . $soutenance->id);

        if ($etudiant) {
            Notification::create([
                'user_id' => $etudiant->id,
                'message' => "Votre soutenance pour le projet « {$titre} » est terminée. L'évaluation est en cours.",
            ]);
        }

        return response()->json(['message' => 'Soutenance marquée comme terminée.', 'statut' => 'termine']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function formatPlan(PlanSoutenance $p): array
    {
        $debut = $p->heure_debut ? substr($p->heure_debut, 0, 5) : null;
        $fin   = $p->heure_fin   ? substr($p->heure_fin,   0, 5) : null;
        $duree = null;

        if ($debut && $fin) {
            $toMin = fn(string $t): int => (int) substr($t, 0, 2) * 60 + (int) substr($t, 3, 2);
            $diff  = $toMin($fin) - $toMin($debut);
            if ($diff > 0) {
                $duree = ($diff >= 60 ? intdiv($diff, 60) . 'h' : '')
                       . ($diff % 60 ? str_pad($diff % 60, 2, '0', STR_PAD_LEFT) . 'min' : '');
            }
        }

        return [
            'id'              => $p->id,
            'jury_id'         => $p->jury_id,
            'proposant_id'    => $p->proposant_id,
            'proposant_nom'   => $p->proposant
                ? trim(($p->proposant->prenom ?? '') . ' ' . ($p->proposant->nom ?? ''))
                : 'Inconnu',
            'fonction'        => $p->fonction,
            'statut'          => $p->statut,
            'date'            => $p->date,
            'heure_debut'     => $p->heure_debut,
            'heure_fin'       => $p->heure_fin,
            'duree'           => $duree,
            'salle'           => $p->salle,
            'motif_rejet'     => $p->motif_rejet,
            'date_traitement' => $p->date_traitement,
            'projet_titre'    => $p->jury?->projet?->titre ?? null,
            'created_at'      => $p->created_at,
        ];
    }
}