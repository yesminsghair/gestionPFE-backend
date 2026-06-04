<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class EncadrantDashboardController extends Controller
{
    // GET /api/dashboard/encadrant
    public function index(Request $request)
    {
        $encadrantId = $request->user()->id;

        return response()->json([
            'kpi'    => $this->getKpi($encadrantId),
            'charts' => [
                'validationSujets'     => $this->validationSujets($encadrantId),
                'chargeSuiviEtudiants' => $this->chargeSuiviEtudiants($encadrantId),
                'validationRapports'   => $this->validationRapports($encadrantId),
                'avancementMoyen'      => $this->avancementMoyen($encadrantId),
                'pfeEnRetard'          => $this->pfeEnRetard($encadrantId),
                'tauxReussite'         => $this->tauxReussite($encadrantId),
            ],
        ]);
    }

    // ── KPI ──────────────────────────────────────────────────────────────────

    private function getKpi(int $encadrantId): array
    {
        $nbEtudiants = DB::table('affectations')
            ->where('encadrant_id', $encadrantId)
            ->where('statut', 'diffusee')
            ->count();

        $etudiantIds = DB::table('affectations')
            ->where('encadrant_id', $encadrantId)
            ->where('statut', 'diffusee')
            ->pluck('etudiant_id');

        $sujetsValides = DB::table('projets_pfe')
            ->whereIn('etudiant_id', $etudiantIds)
            ->whereNotNull('titre')
            ->where('titre', '!=', '')
            ->count();

        $sujetsTotal    = $nbEtudiants;
        $tauxValidation = $sujetsTotal > 0
            ? round(($sujetsValides / $sujetsTotal) * 100, 1) : 0;

        $avancementMoyen = $this->calculerAvancementMoyen($encadrantId);
        $tauxReussite    = $this->calculerTauxReussite($encadrantId);

        // FIX 1: Include 'effectuee' alongside 'confirmee'.
        // Réunions transition planifiee → confirmee → effectuee after they happen.
        // Counting only 'confirmee' misses all past meetings and gives wrong (low)
        // averages. The Reunion model statut enum has: planifiee, confirmee,
        // annulee, effectuee — both confirmee and effectuee represent real meetings.
        $totalReunions = DB::table('reunions')
            ->where('encadrant_id', $encadrantId)
            ->whereIn('statut', ['confirmee', 'effectuee'])
            ->count();

        $reunionsMoyennes = $nbEtudiants > 0
            ? round($totalReunions / $nbEtudiants, 1) : 0;

        $retardData = $this->pfeEnRetard($encadrantId);
        $tauxRetard = $retardData['taux'];

        return [
            'nbEtudiants'      => $nbEtudiants,
            'tauxValidation'   => $tauxValidation,
            'avancementMoyen'  => round($avancementMoyen, 1),
            'tauxReussite'     => round($tauxReussite, 1),
            'reunionsMoyennes' => $reunionsMoyennes,
            'sujetsValides'    => $sujetsValides,
            'sujetsTotal'      => $sujetsTotal,
            'tauxRetard'       => $tauxRetard,
        ];
    }

    // ── CHARTS ───────────────────────────────────────────────────────────────

    private function validationSujets(int $encadrantId): array
    {
        $etudiantIds = DB::table('affectations')
            ->where('encadrant_id', $encadrantId)
            ->where('statut', 'diffusee')
            ->pluck('etudiant_id');

        $avecSujet = DB::table('projets_pfe')
            ->whereIn('etudiant_id', $etudiantIds)
            ->whereNotNull('titre')
            ->where('titre', '!=', '')
            ->count();

        $sansSujet = DB::table('projets_pfe')
            ->whereIn('etudiant_id', $etudiantIds)
            ->where(function ($q) {
                $q->whereNull('titre')->orWhere('titre', '');
            })
            ->count();

        // Students in affectations but without a projets_pfe row yet
        $sansSujet += max(0, $etudiantIds->count() - $avecSujet - $sansSujet);

        $total = $avecSujet + $sansSujet;
        $taux  = $total > 0 ? round(($avecSujet / $total) * 100, 1) : 0;

        return [
            'taux'   => $taux,
            'labels' => ['Avec sujet', 'Sans sujet'],
            'values' => [$avecSujet, $sansSujet],
        ];
    }

    /**
     * FIX 1 (same as KPI): count 'confirmee' AND 'effectuee' meetings.
     * Meetings that already happened are stored as 'effectuee', not 'confirmee'.
     * The original filter on 'confirmee' only returned future/pending meetings,
     * causing this chart to show zero or near-zero bars for most students.
     */
    private function chargeSuiviEtudiants(int $encadrantId): array
    {
        $etudiantIds = DB::table('affectations')
            ->where('encadrant_id', $encadrantId)
            ->where('statut', 'diffusee')
            ->pluck('etudiant_id');

        $data = DB::table('reunions')
            ->join('utilisateurs', 'utilisateurs.id', '=', 'reunions.etudiant_id')
            ->where('reunions.encadrant_id', $encadrantId)
            ->whereIn('reunions.etudiant_id', $etudiantIds)
            ->whereIn('reunions.statut', ['confirmee', 'effectuee']) // FIX 1
            ->select(
                DB::raw("CONCAT(utilisateurs.prenom, ' ', utilisateurs.nom) as name"),
                DB::raw('COUNT(*) as nb_reunions')
            )
            ->groupBy('reunions.etudiant_id', 'name')
            ->orderByDesc('nb_reunions')
            ->get();

        return [
            'labels' => $data->pluck('name')->values(),
            'values' => $data->pluck('nb_reunions')->values(),
        ];
    }

    /**
     * FIX 2: Exclude 'remplace' and 'retire' livrables from the total.
     * The livrables table statut enum is: en_attente, valide, rejete, remplace, retire.
     * Counting ALL rows (including replaced/withdrawn) inflates the denominator
     * and makes the validation rate appear lower than it really is.
     * Only active livrables (en_attente, valide, rejete) should count.
     */
    private function validationRapports(int $encadrantId): array
    {
        $etudiantIds = DB::table('affectations')
            ->where('encadrant_id', $encadrantId)
            ->where('statut', 'diffusee')
            ->pluck('etudiant_id');

        if ($etudiantIds->isEmpty()) {
            return [
                'taux'   => 0,
                'labels' => ['Validés', 'En attente', 'Rejetés'],
                'values' => [0, 0, 0],
            ];
        }

        $row = DB::table('livrables')
            ->whereIn('etudiant_id', $etudiantIds)
            ->whereIn('statut', ['valide', 'en_attente', 'rejete']) // FIX 2: exclude remplace/retire
            ->selectRaw("
                SUM(CASE WHEN statut = 'valide'     THEN 1 ELSE 0 END) as valides,
                SUM(CASE WHEN statut = 'en_attente' THEN 1 ELSE 0 END) as en_attente,
                SUM(CASE WHEN statut = 'rejete'     THEN 1 ELSE 0 END) as rejetes,
                COUNT(*) as total
            ")
            ->first();

        $valides   = (int) ($row->valides    ?? 0);
        $enAttente = (int) ($row->en_attente ?? 0);
        $rejetes   = (int) ($row->rejetes    ?? 0);
        $total     = (int) ($row->total      ?? 0);
        $taux      = $total > 0 ? round(($valides / $total) * 100, 1) : 0;

        return [
            'taux'   => $taux,
            'labels' => ['Validés', 'En attente', 'Rejetés'],
            'values' => [$valides, $enAttente, $rejetes],
        ];
    }

    /**
     * FIX 3: Use suivi_etudiant_phase row count as the denominator instead of
     * phases WHERE chef_id = $aff->chef_id.
     *
     * The old denominator counts ALL phases ever created by the chef, including
     * phases added after this student's affectation, phases for other cohorts,
     * or phases not yet assigned to students. This makes avancement appear lower
     * than it really is (e.g. 5 validated out of 10 chef phases = 50%, but if
     * only 7 phases were actually assigned to this student it should be 71%).
     *
     * The Affectation model's getProgressionAttribute() uses the same approach:
     *   $total = $this->suiviPhases()->count();  ← rows in suivi_etudiant_phase
     * We mirror that logic here.
     */
    private function avancementMoyen(int $encadrantId): array
    {
        $affs = DB::table('affectations')
            ->where('encadrant_id', $encadrantId)
            ->where('statut', 'diffusee')
            ->get();

        if ($affs->isEmpty()) {
            return ['taux' => 0, 'labels' => [], 'values' => []];
        }

        $details         = [];
        $totalAvancement = 0;

        foreach ($affs as $aff) {
            // FIX 3: count only phases actually tracked for this affectation
            $totalPhases = DB::table('suivi_etudiant_phase')
                ->where('affectation_id', $aff->id)
                ->count();

            $validees = DB::table('suivi_etudiant_phase')
                ->where('affectation_id', $aff->id)
                ->where('statut', 'validee')
                ->count();

            $pct = $totalPhases > 0 ? round(($validees / $totalPhases) * 100) : 0;

            $etu       = DB::table('utilisateurs')->find($aff->etudiant_id);
            $details[] = [
                'nom'      => $etu
                    ? $etu->prenom . ' ' . $etu->nom
                    : 'Étudiant #' . $aff->etudiant_id,
                'progress' => $pct,
            ];
            $totalAvancement += $pct;
        }

        $moyen = round($totalAvancement / $affs->count(), 1);

        return [
            'taux'   => $moyen,
            'labels' => collect($details)->pluck('nom')->values(),
            'values' => collect($details)->pluck('progress')->values(),
        ];
    }

    private function pfeEnRetard(int $encadrantId): array
    {
        $affs = DB::table('affectations')
            ->where('encadrant_id', $encadrantId)
            ->where('statut', 'diffusee')
            ->get();

        $labels        = [];
        $values        = [];   // actual count of late phases per student
        $studentsLate  = 0;    // how many students have at least 1 late phase

        foreach ($affs as $aff) {
            $etu      = DB::table('utilisateurs')->find($aff->etudiant_id);
            $labels[] = $etu
                ? $etu->prenom . ' ' . $etu->nom
                : 'Étudiant #' . $aff->etudiant_id;

            // Count phases past their deadline that are still not validated
            $retard = DB::table('suivi_etudiant_phase')
                ->join('phases', 'phases.id', '=', 'suivi_etudiant_phase.phase_id')
                ->where('suivi_etudiant_phase.affectation_id', $aff->id)
                ->where('phases.date_fin', '<', now())
                ->where('suivi_etudiant_phase.statut', '!=', 'validee')
                ->count();

            $values[] = $retard;            // real count, not binary
            if ($retard > 0) $studentsLate++;
        }

        $total = count($values);
        // taux = % of students who have at least one late phase
        $taux  = $total > 0 ? round(($studentsLate / $total) * 100, 1) : 0;

        return [
            'taux'   => $taux,
            'labels' => $labels,
            'values' => $values,
        ];
    }

    /**
     * FIX 4: Use ->where('publie', 1) instead of ->where('publie', true).
     * ResultatPfe casts 'publie' as boolean in the Model, but DB::table() bypasses
     * Eloquent casts entirely and talks raw SQL. MySQL stores tinyint(1); passing
     * PHP true gets cast to integer 1 by PDO in most configs, but it is fragile
     * and driver-dependent. Using the literal integer 1 is unambiguous and safe.
     */
    private function tauxReussite(int $encadrantId): array
    {
        $taux = $this->calculerTauxReussite($encadrantId);

        $etudiantIds = DB::table('affectations')
            ->where('encadrant_id', $encadrantId)
            ->where('statut', 'diffusee')
            ->pluck('etudiant_id');

        $admis = DB::table('resultats_pfe')
            ->whereIn('etudiant_id', $etudiantIds)
            ->where('decision', 'admis')
            ->where('publie', 1) // FIX 4
            ->count();

        $ajournes = DB::table('resultats_pfe')
            ->whereIn('etudiant_id', $etudiantIds)
            ->where('decision', 'ajourne')
            ->where('publie', 1) // FIX 4
            ->count();

        return [
            'taux'     => round($taux, 1),
            'admis'    => $admis,
            'ajournes' => $ajournes,
            'labels'   => ['Admis', 'Ajourné'],
            'values'   => [$admis, $ajournes],
        ];
    }

    // ── HELPERS ──────────────────────────────────────────────────────────────

    /**
     * FIX 3 (same as avancementMoyen): use suivi_etudiant_phase row count
     * as denominator, not phases WHERE chef_id.
     */
    private function calculerAvancementMoyen(int $encadrantId): float
    {
        $affs = DB::table('affectations')
            ->where('encadrant_id', $encadrantId)
            ->where('statut', 'diffusee')
            ->get();

        if ($affs->isEmpty()) return 0.0;

        $total = 0;
        foreach ($affs as $aff) {
            // FIX 3: count rows in suivi_etudiant_phase, not all chef phases
            $totalPhases = DB::table('suivi_etudiant_phase')
                ->where('affectation_id', $aff->id)
                ->count();

            $validees = DB::table('suivi_etudiant_phase')
                ->where('affectation_id', $aff->id)
                ->where('statut', 'validee')
                ->count();

            $total += $totalPhases > 0 ? ($validees / $totalPhases) * 100 : 0;
        }

        return $total / $affs->count();
    }

    /**
     * FIX 4 (same as tauxReussite): use ->where('publie', 1).
     */
    private function calculerTauxReussite(int $encadrantId): float
    {
        $etudiantIds = DB::table('affectations')
            ->where('encadrant_id', $encadrantId)
            ->where('statut', 'diffusee')
            ->pluck('etudiant_id');

        if ($etudiantIds->isEmpty()) return 0.0;

        $total = DB::table('resultats_pfe')
            ->whereIn('etudiant_id', $etudiantIds)
            ->where('publie', 1) // FIX 4
            ->count();

        if ($total === 0) return 0.0;

        $admis = DB::table('resultats_pfe')
            ->whereIn('etudiant_id', $etudiantIds)
            ->where('decision', 'admis')
            ->where('publie', 1) // FIX 4
            ->count();

        return ($admis / $total) * 100;
    }
}