<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class ChefDashboardController extends Controller
{
    // GET /api/dashboard/chef
    public function index(Request $request)
    {
        $chefId = $request->user()->id;

        // Compute surcharge once — needed by both kpi and charts
        $surcharge = $this->surchargeEncadrants($chefId);

        $kpi = $this->getKpi($chefId);
        // BUG FIX: tauxSurcharge must live on the kpi object so the Vue KPI
        // card can read it (frontend was patched to expect kpi.tauxSurcharge).
        $kpi['tauxSurcharge'] = $surcharge['tauxSurcharge'];

        return response()->json([
            'kpi'    => $kpi,
            'charts' => [
                'chargeEncadrants'         => $this->chargeEncadrants($chefId),
                'surchargeEncadrants'      => $surcharge,
                'planificationSoutenances' => $this->planificationSoutenances($chefId),
                'respectCalendrier'        => $this->respectCalendrier($chefId),
                'respectPhases'            => $this->respectPhases($chefId),
                'retardParEncadrant'       => $this->retardParEncadrant($chefId),
            ],
        ]);
    }

    // ── KPI ──────────────────────────────────────────────────────────────────

    private function getKpi(int $chefId): array
    {
        // BUG FIX: all affectation-based counts must be scoped to statut =
        // 'diffusee'. 'en_cours' affectations are draft assignments — the chef
        // hasn't published them yet, so they should not appear in any metric.
        // Affectation::STATUT_DIFFUSEE = 'diffusee' (confirmed by model constants).

        $totalEtudiants = DB::table('affectations')
            ->where('chef_id', $chefId)
            ->where('statut', 'diffusee')
            ->count();

        $totalEncadrants = DB::table('affectations')
            ->where('chef_id', $chefId)
            ->where('statut', 'diffusee')
            ->whereNotNull('encadrant_id')
            ->distinct('encadrant_id')
            ->count('encadrant_id');

        $chargeMoyenne = $totalEncadrants > 0
            ? round($totalEtudiants / $totalEncadrants, 1) : 0;

        // BUG FIX: soutenances are linked to projects, not directly to
        // affectations. Correct join path:
        //   soutenances.projet_id → projets_pfe.id
        //   projets_pfe.etudiant_id → affectations.etudiant_id
        //   affectations.chef_id = $chefId AND statut = 'diffusee'
        // The original query was correct structurally but missed the statut filter.
        $totalSoutenances = DB::table('soutenances')
            ->join('projets_pfe', 'projets_pfe.id', '=', 'soutenances.projet_id')
            ->join('affectations', 'affectations.etudiant_id', '=', 'projets_pfe.etudiant_id')
            ->where('affectations.chef_id', $chefId)
            ->where('affectations.statut', 'diffusee')
            ->count();

        $soutenancesPlanifiees = DB::table('soutenances')
            ->join('projets_pfe', 'projets_pfe.id', '=', 'soutenances.projet_id')
            ->join('affectations', 'affectations.etudiant_id', '=', 'projets_pfe.etudiant_id')
            ->where('affectations.chef_id', $chefId)
            ->where('affectations.statut', 'diffusee')
            // BUG FIX: 'publie' is the correct statut for a scheduled/published
            // soutenance (per Soutenance model DDL comment and soutenances DDL).
            // The original used whereNotNull('date_soutenance') which matches
            // 'termine' rows too, over-counting planifiées.
            ->whereIn('soutenances.statut', ['publie', 'termine'])
            ->count();

        $tauxPlanification = $totalSoutenances > 0
            ? round(($soutenancesPlanifiees / $totalSoutenances) * 100, 1) : 0;

        $tauxRetard = $this->calculerTauxRetard($chefId);

        return [
            'totalSoutenances'  => $totalSoutenances,
            'totalEncadrants'   => $totalEncadrants,
            'chargeMoyenne'     => $chargeMoyenne,
            'tauxRetard'        => round($tauxRetard, 1),
            'tauxPlanification' => $tauxPlanification,
            'totalEtudiants'    => $totalEtudiants,
            // tauxSurcharge is injected by index() after calling surchargeEncadrants()
        ];
    }

    // ── CHARTS ───────────────────────────────────────────────────────────────

    /**
     * Histogramme : charge par encadrant (nb étudiants assignés).
     *
     * BUG FIX: added statut = 'diffusee' filter — draft affectations must not
     * inflate individual encadrant loads.
     */
    private function chargeEncadrants(int $chefId): array
    {
        $data = DB::table('affectations')
            ->join('utilisateurs', 'utilisateurs.id', '=', 'affectations.encadrant_id')
            ->where('affectations.chef_id', $chefId)
            ->where('affectations.statut', 'diffusee')
            ->whereNotNull('affectations.encadrant_id')
            ->select(
                DB::raw("CONCAT(utilisateurs.prenom, ' ', utilisateurs.nom) as name"),
                DB::raw('COUNT(*) as total')
            )
            ->groupBy('affectations.encadrant_id', 'name')
            ->orderByDesc('total')
            ->get();

        return [
            'labels' => $data->pluck('name')->values(),
            'values' => $data->pluck('total')->values(),
        ];
    }

    /**
     * Barres : surcharge par encadrant + taux global de surcharge.
     *
     * BUG FIX: added statut = 'diffusee' — same reason as chargeEncadrants.
     *
     * tauxSurcharge is also promoted to the top-level kpi object by index()
     * so the Vue KPI card can read kpi.tauxSurcharge directly.
     */
    private function surchargeEncadrants(int $chefId): array
    {
        $seuil = 5;

        $data = DB::table('affectations')
            ->join('utilisateurs', 'utilisateurs.id', '=', 'affectations.encadrant_id')
            ->where('affectations.chef_id', $chefId)
            ->where('affectations.statut', 'diffusee')
            ->whereNotNull('affectations.encadrant_id')
            ->select(
                DB::raw("CONCAT(utilisateurs.prenom, ' ', utilisateurs.nom) as name"),
                DB::raw('COUNT(*) as total')
            )
            ->groupBy('affectations.encadrant_id', 'name')
            ->get();

        $surcharges    = $data->where('total', '>', $seuil);
        $tauxSurcharge = $data->count() > 0
            ? round(($surcharges->count() / $data->count()) * 100, 1) : 0;

        return [
            'labels'        => $data->pluck('name')->values(),
            'values'        => $data->pluck('total')->values(),
            'seuil'         => $seuil,
            'tauxSurcharge' => $tauxSurcharge,
        ];
    }

    /**
     * Jauge : taux de planification des soutenances.
     *
     * BUG FIX 1: statut = 'diffusee' filter on affectations throughout.
     * BUG FIX 2: "planifiées" = statut IN ('publie','termine'), not
     *   whereNotNull('date_soutenance'). A soutenance in 'en_attente' may
     *   already have a date set from the plan but has NOT been published yet —
     *   it should not count as planned from a pilotage perspective.
     * BUG FIX 3: collapsed three near-identical subqueries into one aggregation
     *   to avoid redundant joins.
     */
    private function planificationSoutenances(int $chefId): array
    {
        $row = DB::table('soutenances')
            ->join('projets_pfe', 'projets_pfe.id', '=', 'soutenances.projet_id')
            ->join('affectations', 'affectations.etudiant_id', '=', 'projets_pfe.etudiant_id')
            ->where('affectations.chef_id', $chefId)
            ->where('affectations.statut', 'diffusee')
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN soutenances.statut IN ('publie','termine') THEN 1 ELSE 0 END) as planifiees,
                SUM(CASE WHEN soutenances.statut = 'termine'             THEN 1 ELSE 0 END) as terminees,
                SUM(CASE WHEN soutenances.statut = 'en_attente'          THEN 1 ELSE 0 END) as en_attente
            ")
            ->first();

        $total      = (int) ($row->total      ?? 0);
        $planifiees = (int) ($row->planifiees  ?? 0);
        $terminees  = (int) ($row->terminees   ?? 0);
        $enAttente  = (int) ($row->en_attente  ?? 0);

        $taux = $total > 0 ? round(($planifiees / $total) * 100, 1) : 0;

        return [
            'taux'       => $taux,
            'total'      => $total,
            'planifiees' => $planifiees,
            'restantes'  => $enAttente,   // alias used by Vue card footer
            'terminees'  => $terminees,
            'labels'     => ['Planifiées', 'Non planifiées'],
            'values'     => [$planifiees, max(0, $total - $planifiees)],
        ];
    }

    /**
     * Courbe : respect du calendrier des soutenances par mois.
     *
     * BUG FIX: added statut = 'diffusee' on affectations join.
     * Logic is correct: prévues = soutenances with a published/done date,
     * à_temps = statut 'termine' and date not in the future.
     */
    private function respectCalendrier(int $chefId): array
    {
        $data = DB::table('soutenances')
            ->join('projets_pfe', 'projets_pfe.id', '=', 'soutenances.projet_id')
            ->join('affectations', 'affectations.etudiant_id', '=', 'projets_pfe.etudiant_id')
            ->where('affectations.chef_id', $chefId)
            ->where('affectations.statut', 'diffusee')
            ->whereNotNull('soutenances.date_soutenance')
            ->select(
                DB::raw("DATE_FORMAT(soutenances.date_soutenance, '%Y-%m') as mois"),
                DB::raw("COUNT(*) as total"),
                DB::raw("SUM(CASE WHEN soutenances.statut = 'termine'
                              AND soutenances.date_soutenance <= CURDATE()
                              THEN 1 ELSE 0 END) as a_temps")
            )
            ->groupBy('mois')
            ->orderBy('mois')
            ->get();

        return [
            'labels' => $data->pluck('mois')->values(),
            'total'  => $data->pluck('total')->values(),
            'aTemps' => $data->pluck('a_temps')->values(),
        ];
    }

    /**
     * Courbe : taux de respect des phases PFE par phase.
     *
     * BUG FIX (critical): original denominator was
     *   DB::table('affectations')->where('chef_id',$chefId)->count()
     * which counts ALL affectations including 'en_cours'. Students with
     * en_cours affectations have no suivi_etudiant_phase rows yet, so the
     * denominator is inflated and every percentage is artificially deflated.
     *
     * Fix: denominator = count of 'diffusee' affectations only, since only
     * published affectations have suivi rows created for them.
     *
     * Also: suivi_etudiant_phase is linked to affectation_id, not directly
     * to chef_id. We must scope through affectations to avoid counting suivi
     * rows from other chefs' phases that happen to share the same phase_id
     * (unlikely but possible if phases are reused).
     */
    private function respectPhases(int $chefId): array
    {
        $phases = DB::table('phases')
            ->where('chef_id', $chefId)
            ->orderBy('ordre')
            ->get();

        // Correct denominator: only published affectations have suivi rows
        $totalEtudiants = DB::table('affectations')
            ->where('chef_id', $chefId)
            ->where('statut', 'diffusee')
            ->count();

        $labels = [];
        $values = [];

        foreach ($phases as $phase) {
            $labels[] = $phase->nom;

            if ($totalEtudiants === 0) {
                $values[] = 0;
                continue;
            }

            // Count validations scoped through affectations to ensure we only
            // count students belonging to THIS chef's diffusee affectations.
            $aJour = DB::table('suivi_etudiant_phase')
                ->join('affectations', 'affectations.id', '=', 'suivi_etudiant_phase.affectation_id')
                ->where('suivi_etudiant_phase.phase_id', $phase->id)
                ->where('suivi_etudiant_phase.statut', 'validee')
                ->where('affectations.chef_id', $chefId)
                ->where('affectations.statut', 'diffusee')
                ->count();

            $values[] = round(($aJour / $totalEtudiants) * 100, 1);
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /**
     * Barres : taux de retard par encadrant.
     *
     * BUG FIX: the inner query joining affectations to suivi_etudiant_phase
     * was missing statut = 'diffusee' on affectations, so en_cours students
     * (who have no phases yet) were included in total_etu, deflating taux.
     * Added statut filter on both the outer encadrant list and inner retard count.
     */
    private function retardParEncadrant(int $chefId): array
    {
        $encadrants = DB::table('affectations')
            ->join('utilisateurs', 'utilisateurs.id', '=', 'affectations.encadrant_id')
            ->where('affectations.chef_id', $chefId)
            ->where('affectations.statut', 'diffusee')
            ->whereNotNull('affectations.encadrant_id')
            ->select(
                'affectations.encadrant_id',
                DB::raw("CONCAT(utilisateurs.prenom, ' ', utilisateurs.nom) as name"),
                DB::raw('COUNT(*) as total_etu')
            )
            ->groupBy('affectations.encadrant_id', 'name')
            ->get();

        $labels = [];
        $values = [];

        foreach ($encadrants as $enc) {
            $labels[] = $enc->name;

            $enRetard = DB::table('affectations')
                ->join('suivi_etudiant_phase', 'suivi_etudiant_phase.affectation_id', '=', 'affectations.id')
                ->join('phases', 'phases.id', '=', 'suivi_etudiant_phase.phase_id')
                ->where('affectations.encadrant_id', $enc->encadrant_id)
                ->where('affectations.chef_id', $chefId)
                ->where('affectations.statut', 'diffusee')
                ->where('phases.date_fin', '<', now())
                ->where('suivi_etudiant_phase.statut', '!=', 'validee')
                ->distinct('affectations.etudiant_id')
                ->count('affectations.etudiant_id');

            $taux     = $enc->total_etu > 0
                ? round(($enRetard / $enc->total_etu) * 100, 1) : 0;
            $values[] = $taux;
        }

        return ['labels' => $labels, 'values' => $values];
    }

    // ── HELPERS ──────────────────────────────────────────────────────────────

    /**
     * BUG FIX: added statut = 'diffusee' filter.
     * en_cours students have no suivi rows, so they'd never appear in the
     * enRetard count — but they inflate totalEtudiants, masking real retard rates.
     */
    private function calculerTauxRetard(int $chefId): float
    {
        $totalEtudiants = DB::table('affectations')
            ->where('chef_id', $chefId)
            ->where('statut', 'diffusee')
            ->count();

        if ($totalEtudiants === 0) return 0.0;

        $enRetard = DB::table('affectations')
            ->join('suivi_etudiant_phase', 'suivi_etudiant_phase.affectation_id', '=', 'affectations.id')
            ->join('phases', 'phases.id', '=', 'suivi_etudiant_phase.phase_id')
            ->where('affectations.chef_id', $chefId)
            ->where('affectations.statut', 'diffusee')
            ->where('phases.date_fin', '<', now())
            ->where('suivi_etudiant_phase.statut', '!=', 'validee')
            ->distinct('affectations.etudiant_id')
            ->count('affectations.etudiant_id');

        return ($enRetard / $totalEtudiants) * 100;
    }
}