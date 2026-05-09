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

        return response()->json([
            'kpi'    => $this->getKpi($chefId),
            'charts' => [
                'chargeEncadrants'          => $this->chargeEncadrants($chefId),
                'surchargeEncadrants'       => $this->surchargeEncadrants($chefId),
                'planificationSoutenances'  => $this->planificationSoutenances($chefId),
                'respectCalendrier'         => $this->respectCalendrier($chefId),
                'respectPhases'             => $this->respectPhases($chefId),
                'retardParEncadrant'        => $this->retardParEncadrant($chefId),
            ],
        ]);
    }

    // ──────────────────────────────────────────
    // KPI CARDS
    // ──────────────────────────────────────────

    private function getKpi(int $chefId): array
    {
        // Total soutenances (soutenances for students of this chef)
        $totalSoutenances = DB::table('soutenances')
            ->join('projets_pfe', 'projets_pfe.id', '=', 'soutenances.projet_id')
            ->join('affectations', 'affectations.etudiant_id', '=', 'projets_pfe.etudiant_id')
            ->where('affectations.chef_id', $chefId)
            ->count();

        // Total encadrants distincts de la spécialité du chef
        $totalEncadrants = DB::table('affectations')
            ->where('chef_id', $chefId)
            ->whereNotNull('encadrant_id')
            ->distinct('encadrant_id')
            ->count('encadrant_id');

        // Charge moyenne par encadrant
        $totalEtudiants = DB::table('affectations')->where('chef_id', $chefId)->count();
        $chargeMoyenne  = $totalEncadrants > 0 ? round($totalEtudiants / $totalEncadrants, 1) : 0;

        // Taux retard (phases non validées dont date_fin passée)
        $tauxRetard = $this->calculerTauxRetard($chefId);

        // Taux planification soutenances
        $soutenancesPlanifiees = DB::table('soutenances')
            ->join('projets_pfe', 'projets_pfe.id', '=', 'soutenances.projet_id')
            ->join('affectations', 'affectations.etudiant_id', '=', 'projets_pfe.etudiant_id')
            ->where('affectations.chef_id', $chefId)
            ->whereNotNull('soutenances.date_soutenance')
            ->count();
        $tauxPlanification = $totalSoutenances > 0
            ? round(($soutenancesPlanifiees / $totalSoutenances) * 100, 1) : 0;

        return [
            'totalSoutenances' => $totalSoutenances,
            'totalEncadrants'  => $totalEncadrants,
            'chargeMoyenne'    => $chargeMoyenne,
            'tauxRetard'       => round($tauxRetard, 1),
            'tauxPlanification'=> $tauxPlanification,
            'totalEtudiants'   => $totalEtudiants,
        ];
    }

    // ──────────────────────────────────────────
    // CHART DATA
    // ──────────────────────────────────────────

    /**
     * Histogramme : charge par encadrant (nb étudiants assignés)
     */
    private function chargeEncadrants(int $chefId): array
    {
        $data = DB::table('affectations')
            ->join('utilisateurs', 'utilisateurs.id', '=', 'affectations.encadrant_id')
            ->where('affectations.chef_id', $chefId)
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
     * Barres : taux de surcharge (seuil = 5 étudiants)
     */
    private function surchargeEncadrants(int $chefId): array
    {
        $seuil = 5;

        $data = DB::table('affectations')
            ->join('utilisateurs', 'utilisateurs.id', '=', 'affectations.encadrant_id')
            ->where('affectations.chef_id', $chefId)
            ->whereNotNull('affectations.encadrant_id')
            ->select(
                DB::raw("CONCAT(utilisateurs.prenom, ' ', utilisateurs.nom) as name"),
                DB::raw('COUNT(*) as total')
            )
            ->groupBy('affectations.encadrant_id', 'name')
            ->get();

        $surcharges = $data->where('total', '>', $seuil);
        $tauxSurcharge = $data->count() > 0
            ? round(($surcharges->count() / $data->count()) * 100, 1) : 0;

        return [
            'labels' => $data->pluck('name')->values(),
            'values' => $data->pluck('total')->values(),
            'seuil'  => $seuil,
            'tauxSurcharge' => $tauxSurcharge,
        ];
    }

    /**
     * Jauge : taux de planification des soutenances
     */
    private function planificationSoutenances(int $chefId): array
    {
        $total = DB::table('soutenances')
            ->join('projets_pfe', 'projets_pfe.id', '=', 'soutenances.projet_id')
            ->join('affectations', 'affectations.etudiant_id', '=', 'projets_pfe.etudiant_id')
            ->where('affectations.chef_id', $chefId)
            ->count();

        $planifiees = DB::table('soutenances')
            ->join('projets_pfe', 'projets_pfe.id', '=', 'soutenances.projet_id')
            ->join('affectations', 'affectations.etudiant_id', '=', 'projets_pfe.etudiant_id')
            ->where('affectations.chef_id', $chefId)
            ->whereNotNull('soutenances.date_soutenance')
            ->count();

        $terminees = DB::table('soutenances')
            ->join('projets_pfe', 'projets_pfe.id', '=', 'soutenances.projet_id')
            ->join('affectations', 'affectations.etudiant_id', '=', 'projets_pfe.etudiant_id')
            ->where('affectations.chef_id', $chefId)
            ->where('soutenances.statut', 'termine')
            ->count();

        $taux = $total > 0 ? round(($planifiees / $total) * 100, 1) : 0;

        return [
            'taux'       => $taux,
            'total'      => $total,
            'planifiees' => $planifiees,
            'terminees'  => $terminees,
            'labels'     => ['Planifiées', 'Non planifiées'],
            'values'     => [$planifiees, max(0, $total - $planifiees)],
        ];
    }

    /**
     * Courbe : respect calendrier soutenances (soutenances à temps vs total par mois)
     */
    private function respectCalendrier(int $chefId): array
    {
        // Soutenances terminées à temps = date_soutenance <= aujourd'hui ET statut = termine
        $data = DB::table('soutenances')
            ->join('projets_pfe', 'projets_pfe.id', '=', 'soutenances.projet_id')
            ->join('affectations', 'affectations.etudiant_id', '=', 'projets_pfe.etudiant_id')
            ->where('affectations.chef_id', $chefId)
            ->whereNotNull('soutenances.date_soutenance')
            ->select(
                DB::raw("DATE_FORMAT(soutenances.date_soutenance, '%Y-%m') as mois"),
                DB::raw("COUNT(*) as total"),
                DB::raw("SUM(CASE WHEN soutenances.statut = 'termine' AND soutenances.date_soutenance <= CURDATE() THEN 1 ELSE 0 END) as a_temps")
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
     * Courbe : taux de respect des phases PFE (étudiants à jour / total) par phase
     */
    private function respectPhases(int $chefId): array
    {
        $phases = DB::table('phases')
            ->where('chef_id', $chefId)
            ->orderBy('ordre')
            ->get();

        $labels = [];
        $values = [];

        $totalEtudiants = DB::table('affectations')->where('chef_id', $chefId)->count();

        foreach ($phases as $phase) {
            $labels[] = $phase->nom;
            $aJour = DB::table('suivi_etudiant_phase')
                ->where('phase_id', $phase->id)
                ->where('statut', 'validee')
                ->count();
            $taux = $totalEtudiants > 0 ? round(($aJour / $totalEtudiants) * 100, 1) : 0;
            $values[] = $taux;
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /**
     * Barres : taux de retard par encadrant
     */
    private function retardParEncadrant(int $chefId): array
    {
        $encadrants = DB::table('affectations')
            ->join('utilisateurs', 'utilisateurs.id', '=', 'affectations.encadrant_id')
            ->where('affectations.chef_id', $chefId)
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

            // Étudiants de cet encadrant avec au moins une phase en retard
            $enRetard = DB::table('affectations')
                ->join('suivi_etudiant_phase', 'suivi_etudiant_phase.affectation_id', '=', 'affectations.id')
                ->join('phases', 'phases.id', '=', 'suivi_etudiant_phase.phase_id')
                ->where('affectations.encadrant_id', $enc->encadrant_id)
                ->where('affectations.chef_id', $chefId)
                ->where('phases.date_fin', '<', now())
                ->where('suivi_etudiant_phase.statut', '!=', 'validee')
                ->distinct('affectations.etudiant_id')
                ->count('affectations.etudiant_id');

            $taux = $enc->total_etu > 0 ? round(($enRetard / $enc->total_etu) * 100, 1) : 0;
            $values[] = $taux;
        }

        return ['labels' => $labels, 'values' => $values];
    }

    // ──────────────────────────────────────────
    // HELPERS
    // ──────────────────────────────────────────

    private function calculerTauxRetard(int $chefId): float
    {
        $totalEtudiants = DB::table('affectations')->where('chef_id', $chefId)->count();
        if ($totalEtudiants === 0) return 0;

        $enRetard = DB::table('affectations')
            ->join('suivi_etudiant_phase', 'suivi_etudiant_phase.affectation_id', '=', 'affectations.id')
            ->join('phases', 'phases.id', '=', 'suivi_etudiant_phase.phase_id')
            ->where('affectations.chef_id', $chefId)
            ->where('phases.date_fin', '<', now())
            ->where('suivi_etudiant_phase.statut', '!=', 'validee')
            ->distinct('affectations.etudiant_id')
            ->count('affectations.etudiant_id');

        return ($enRetard / $totalEtudiants) * 100;
    }
}