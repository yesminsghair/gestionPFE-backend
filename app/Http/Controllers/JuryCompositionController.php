<?php

namespace App\Http\Controllers;

use App\Models\Affectation;
use App\Models\JuryMembrePfe;
use App\Models\Notification;
use App\Models\ProjetPfe;
use App\Models\Soutenance;
use App\Models\Utilisateur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * JuryCompositionController
 *
 * Manages jury composition groups (jury_membres_pfe).
 * Each group is independent — one row per project, with 3 flat role columns.
 *
 * Lifecycle:
 *   1. Chef creates group → encadrant_id auto-filled from affectation
 *   2. Chef sets president_id + examinateur_id
 *   3. Chef publishes → publie=true, notifications sent
 *   4. Members can now propose plans (plans_soutenance)
 *   5. Chef validates a plan → soutenance session created (handled by SoutenancePlanificationController)
 *
 * Routes prefix: /api/jurys-pfe
 *   GET    /                           → index
 *   POST   /                           → store
 *   GET    /{jury}                     → show
 *   DELETE /{jury}                     → destroy
 *   PUT    /{jury}/president           → setPresident
 *   PUT    /{jury}/examinateur         → setExaminateur
 *   DELETE /{jury}/president           → clearPresident
 *   DELETE /{jury}/examinateur         → clearExaminateur
 *   POST   /{jury}/publier-jury        → publierJury
 *   POST   /{jury}/modifier-jury       → modifierJury
 *   GET    /etudiants-du-chef          → etudiantsDuChef
 *   GET    /enseignants-departement    → enseignantsDuDepartement
 */
class JuryCompositionController extends Controller
{
    // ── Index (for plan proposal + session planning) ──────────────

    /**
     * GET /api/jurys-pfe
     * Returns published jury groups scoped to the authenticated user:
     *  - Chef: all published groups in their department
     *  - Encadrant/Jury member: only groups where they appear as a member AND publie=true
     */
    public function index(): JsonResponse
    {
        $user = Auth::user();

        $query = JuryMembrePfe::with([
            'projet.etudiant',
            'chef',
            'encadrant',
            'president',
            'examinateur',
            'soutenance',
        ]);

        if (in_array($user->role, ['chef_departement', 'chef', 'admin'])) {
            // Chef sees all published groups in their department
            $query->whereHas('projet.etudiant', fn($q) =>
                $q->where('specialite_id', $user->specialite_id)
            )->where('publie', true);
        } else {
            // Jury member / encadrant sees only groups they belong to AND that are published
            $uid = $user->id;
            $query->where('publie', true)
                  ->where(fn($q) => $q
                      ->where('encadrant_id', $uid)
                      ->orWhere('president_id', $uid)
                      ->orWhere('examinateur_id', $uid)
                  );
        }

        return response()->json(
            $query->get()->map(fn($j) => $this->format($j))
        );
    }

    // ── CRUD ──────────────────────────────────────────────────────

    /**
     * POST /api/jurys-pfe
     * Creates a jury composition group for a given project.
     * Auto-fills encadrant_id from the project's active affectation.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'projet_id' => 'required|exists:projets_pfe,id|unique:jury_membres_pfe,projet_id',
        ]);

        $chef   = Auth::user();
        $projet = ProjetPfe::with('etudiant')->findOrFail($data['projet_id']);

        // Resolve encadrant from the active diffusee affectation
        $encadrantId = null;
        if ($projet->etudiant_id) {
            $affectation = Affectation::where('etudiant_id', $projet->etudiant_id)
                ->where('statut', Affectation::STATUT_DIFFUSEE)
                ->whereNotNull('encadrant_id')
                ->latest()
                ->first();
            $encadrantId = $affectation?->encadrant_id;
        }

        $jury = JuryMembrePfe::create([
            'chef_id'      => $chef->id,
            'projet_id'    => $projet->id,
            'encadrant_id' => $encadrantId,
            'publie'       => false,
        ]);

        return response()->json($this->format($jury->load(['projet.etudiant', 'encadrant', 'president', 'examinateur'])), 201);
    }

    public function show(JuryMembrePfe $juryPfe): JsonResponse
    {
        return response()->json($this->format(
            $juryPfe->load(['projet.etudiant', 'chef', 'encadrant', 'president', 'examinateur', 'soutenance'])
        ));
    }

    public function destroy(JuryMembrePfe $juryPfe): JsonResponse
    {
        if ($juryPfe->publie) {
            return response()->json(['message' => 'Un jury publié ne peut pas être supprimé.'], 422);
        }
        $juryPfe->delete();
        return response()->json(['message' => 'Jury supprimé.']);
    }

    // ── Role assignment ───────────────────────────────────────────

    /**
     * PUT /api/jurys-pfe/{jury}/president
     * Sets the président de jury.
     */
    public function setPresident(Request $request, JuryMembrePfe $juryPfe): JsonResponse
    {
        return $this->setRole($request, $juryPfe, 'president');
    }

    /**
     * PUT /api/jurys-pfe/{jury}/examinateur
     * Sets the examinateur.
     */
    public function setExaminateur(Request $request, JuryMembrePfe $juryPfe): JsonResponse
    {
        return $this->setRole($request, $juryPfe, 'examinateur');
    }

    /**
     * DELETE /api/jurys-pfe/{jury}/president
     * Clears the président slot.
     */
    public function clearPresident(JuryMembrePfe $juryPfe): JsonResponse
    {
        return $this->clearRole($juryPfe, 'president');
    }

    /**
     * DELETE /api/jurys-pfe/{jury}/examinateur
     * Clears the examinateur slot.
     */
    public function clearExaminateur(JuryMembrePfe $juryPfe): JsonResponse
    {
        return $this->clearRole($juryPfe, 'examinateur');
    }

    private function setRole(Request $request, JuryMembrePfe $juryPfe, string $role): JsonResponse
    {
        if ($juryPfe->publie) {
            return response()->json(['message' => 'Le jury est publié — utilisez "Modifier" pour le déverrouiller.'], 422);
        }

        $data = $request->validate([
            'enseignant_id' => 'required|exists:utilisateurs,id',
        ]);

        $uid = (int) $data['enseignant_id'];

        // Guard: same person cannot hold two roles
        if ($juryPfe->encadrant_id === $uid && $role !== 'encadrant') {
            return response()->json(['message' => 'Cet enseignant est déjà encadrant de ce jury.'], 422);
        }
        $otherRole = $role === 'president' ? 'examinateur_id' : 'president_id';
        if ($juryPfe->$otherRole === $uid) {
            $otherLabel = $role === 'president' ? 'examinateur' : 'président';
            return response()->json(['message' => "Cet enseignant est déjà {$otherLabel} de ce jury."], 422);
        }

        $juryPfe->update(["{$role}_id" => $uid]);

        return response()->json($this->format($juryPfe->fresh(['projet.etudiant', 'encadrant', 'president', 'examinateur'])));
    }

    private function clearRole(JuryMembrePfe $juryPfe, string $role): JsonResponse
    {
        if ($juryPfe->publie) {
            return response()->json(['message' => 'Le jury est publié — utilisez "Modifier" pour le déverrouiller.'], 422);
        }

        $juryPfe->update(["{$role}_id" => null]);

        return response()->json($this->format($juryPfe->fresh(['projet.etudiant', 'encadrant', 'president', 'examinateur'])));
    }

    // ── Publication & Modification ────────────────────────────────

    /**
     * POST /api/jurys-pfe/{jury}/publier-jury
     *
     * Requires all 3 roles to be filled.
     * Sets publie=true, sends notifications to president + examinateur.
     * Detects re-publication (publie was reset via modifierJury) via updated_at > created_at.
     */
    public function publierJury(JuryMembrePfe $juryPfe): JsonResponse
    {
        $juryPfe->load(['projet.etudiant', 'encadrant', 'president', 'examinateur']);

        if (!$juryPfe->president_id || !$juryPfe->examinateur_id) {
            return response()->json([
                'message' => 'Le jury doit avoir un président et un examinateur avant de pouvoir être publié.',
            ], 422);
        }

        if ($juryPfe->publie) {
            return response()->json(['message' => 'Ce jury est déjà publié.'], 422);
        }

        $etudiantNom = trim(
            ($juryPfe->projet?->etudiant?->prenom ?? '') . ' ' .
            ($juryPfe->projet?->etudiant?->nom    ?? '')
        );
        $projetTitre = $juryPfe->projet?->titre ?? 'Projet PFE';

        // Re-publication detection: modifierJury bumps updated_at
        $isRePublication = $juryPfe->updated_at > $juryPfe->created_at;

        DB::beginTransaction();
        try {
            // Notify encadrant
            if ($juryPfe->encadrant_id) {
                $msgEncadrant = $isRePublication
                    ? "Le jury « {$projetTitre} » (étudiant : {$etudiantNom}) a été mis à jour. Vous êtes confirmé(e) en tant qu'Encadrant(e)."
                    : "Vous êtes assigné(e) en tant qu'Encadrant(e) de l'étudiant {$etudiantNom} pour le projet « {$projetTitre} ».";
                Notification::create(['user_id' => $juryPfe->encadrant_id, 'message' => $msgEncadrant]);
            }

            // Notify président
            $msgPresident = $isRePublication
                ? "Le jury « {$projetTitre} » (étudiant : {$etudiantNom}) a été mis à jour. Vous êtes confirmé(e) en tant que Président(e) de jury."
                : "Vous êtes assigné(e) en tant que Président(e) de jury de l'étudiant {$etudiantNom} pour le projet « {$projetTitre} ».";
            Notification::create(['user_id' => $juryPfe->president_id, 'message' => $msgPresident]);

            // Notify examinateur
            $msgExaminateur = $isRePublication
                ? "Le jury « {$projetTitre} » (étudiant : {$etudiantNom}) a été mis à jour. Vous êtes confirmé(e) en tant qu'Examinateur(trice)."
                : "Vous êtes assigné(e) en tant qu'Examinateur(trice) de l'étudiant {$etudiantNom} pour le projet « {$projetTitre} ».";
            Notification::create(['user_id' => $juryPfe->examinateur_id, 'message' => $msgExaminateur]);

            $juryPfe->update(['publie' => true]);

            DB::commit();
            return response()->json(['message' => 'Jury publié — notifications envoyées à l\'encadrant, au président et à l\'examinateur.']);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('publierJury error: ' . $e->getMessage());
            return response()->json(['message' => 'Erreur lors de la publication : ' . $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/jurys-pfe/{jury}/modifier-jury
     *
     * Resets publie=false so chef can change president/examinateur.
     * No notifications sent — notifications go out only on re-publication.
     */
    public function modifierJury(JuryMembrePfe $juryPfe): JsonResponse
    {
        $juryPfe->update(['publie' => false]);
        return response()->json(['message' => 'Jury remis en modification.']);
    }

    // ── Chef helpers ──────────────────────────────────────────────

    /**
     * GET /api/jurys-pfe/etudiants-du-chef
     *
     * Returns all students in the chef's department with their current
     * jury composition state (flat structure).
     */
    public function etudiantsDuChef(): JsonResponse
    {
        $chef = Auth::user();

        $affectations = Affectation::where('statut', Affectation::STATUT_DIFFUSEE)
            ->whereNotNull('encadrant_id')
            ->whereHas('etudiant', fn($q) => $q->where('specialite_id', $chef->specialite_id))
            ->with(['etudiant', 'encadrant'])
            ->get();

        $etudiantIds = $affectations->pluck('etudiant_id');

        // Load projects with their jury composition and soutenance session
        $projets = ProjetPfe::with([
            'jury.encadrant',
            'jury.president',
            'jury.examinateur',
            'jury.soutenance',
        ])
        ->whereIn('etudiant_id', $etudiantIds)
        ->get()
        ->keyBy('etudiant_id');

        $result = $affectations->map(function (Affectation $aff) use ($projets) {
            $projet = $projets->get($aff->etudiant_id);
            $jury   = $projet?->jury;

            return [
                'etudiant_id'      => $aff->etudiant_id,
                'etudiant_nom'     => trim(($aff->etudiant->prenom ?? '') . ' ' . ($aff->etudiant->nom ?? '')),
                'matricule'        => $aff->etudiant->matricule ?? '--',
                'encadrant_nom'    => trim(($aff->encadrant->prenom ?? '') . ' ' . ($aff->encadrant->nom ?? '')),
                'encadrant_id'     => $aff->encadrant_id,
                'projet_pfe_id'    => $projet?->id,
                'projet_titre'     => $projet?->titre,
                // Jury composition
                'jury_id'          => $jury?->id,
                'publie'           => (bool) ($jury?->publie ?? false),
                'president_id'     => $jury?->president_id,
                'president_nom'    => $jury?->president  ? trim(($jury->president->prenom  ?? '') . ' ' . ($jury->president->nom  ?? '')) : null,
                'examinateur_id'   => $jury?->examinateur_id,
                'examinateur_nom'  => $jury?->examinateur ? trim(($jury->examinateur->prenom ?? '') . ' ' . ($jury->examinateur->nom ?? '')) : null,
                // Session
                'soutenance_id'    => $jury?->soutenance?->id,
                'soutenance_statut'=> $jury?->soutenance?->statut,
            ];
        });

        return response()->json($result);
    }

    /**
     * GET /api/jurys-pfe/enseignants-departement
     */
    public function enseignantsDuDepartement(): JsonResponse
    {
        $chef = Auth::user();

        if (!$chef || !$chef->specialite_id) {
            return response()->json([]);
        }

        $enseignants = Utilisateur::whereIn('role', ['encadrant', 'enseignant', 'jury', 'chef'])
            ->where('specialite_id', $chef->specialite_id)
            ->get()
            ->map(fn($u) => [
                'id'          => $u->id,
                'nom_complet' => trim(($u->prenom ?? '') . ' ' . ($u->nom ?? '')),
                'role'        => $u->role,
            ]);

        return response()->json($enseignants);
    }

    // ── Format helper ─────────────────────────────────────────────

    private function format(JuryMembrePfe $j): array
    {
        $encadrant   = $j->encadrant;
        $president   = $j->president;
        $examinateur = $j->examinateur;
        $soutenance  = $j->soutenance;

        return [
            'id'               => $j->id,
            'chef_id'          => $j->chef_id,
            'projet_id'        => $j->projet_id,
            'projet_titre'     => $j->projet?->titre ?? 'Sans titre',
            'etudiant_id'      => $j->projet?->etudiant_id,
            'etudiant_nom'     => trim((optional($j->projet?->etudiant)->prenom ?? '') . ' ' . (optional($j->projet?->etudiant)->nom ?? '')),
            'publie'           => (bool) $j->publie,
            // Flat roles
            'encadrant_id'     => $j->encadrant_id,
            'encadrant_nom'    => $encadrant   ? trim(($encadrant->prenom   ?? '') . ' ' . ($encadrant->nom   ?? '')) : null,
            'president_id'     => $j->president_id,
            'president_nom'    => $president   ? trim(($president->prenom   ?? '') . ' ' . ($president->nom   ?? '')) : null,
            'examinateur_id'   => $j->examinateur_id,
            'examinateur_nom'  => $examinateur ? trim(($examinateur->prenom ?? '') . ' ' . ($examinateur->nom ?? '')) : null,
            // Completion flags
            'complet'          => (bool) ($j->encadrant_id && $j->president_id && $j->examinateur_id),
            // Linked session (null until a plan is validated)
            'soutenance_id'    => $soutenance?->id,
            'date_soutenance'  => $soutenance?->date_soutenance,
            'heure_debut'      => $soutenance?->heure_debut ? substr($soutenance->heure_debut, 0, 5) : null,
            'heure_fin'        => $soutenance?->heure_fin   ? substr($soutenance->heure_fin,   0, 5) : null,
            'salle'            => $soutenance?->salle,
            'statut'           => $soutenance?->statut ?? null,
            'calendrier_publie'=> (bool) ($soutenance?->calendrier_publie ?? false),
        ];
    }
}