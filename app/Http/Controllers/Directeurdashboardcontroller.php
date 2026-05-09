<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class DirecteurDashboardController extends Controller
{
    // GET /api/dashboard/directeur
    public function index()
    {
        return response()->json([
            'kpi'    => $this->getKpi(),
            'charts' => [
                'soutenancesParSpecialite'  => $this->soutenancesParSpecialite(),
                'encadrantsParSpecialite'   => $this->encadrantsParSpecialite(),
                'etudiantsParSpecialite'    => $this->etudiantsParSpecialite(),
                'tauxReussiteGlobal'        => $this->tauxReussiteGlobal(),
                'soutenancesRealisees'      => $this->soutenancesRealisees(),
                'pfeFinalisesDelais'        => $this->pfeFinalisesDelais(),
            ],
        ]);
    }

    // ── KPI ──────────────────────────────────

    private function getKpi(): array
    {
        $totalSpecialites = DB::table('specialites')->count();

        $totalSoutenances = DB::table('soutenances')->count();

        $soutenancesTerminees = DB::table('soutenances')
            ->where('statut', 'termine')->count();

        $tauxReussite = $this->calculerTauxReussiteGlobal();

        $totalEtudiants = DB::table('utilisateurs')
            ->where('role', 'etudiant')->count();

        $totalEncadrants = DB::table('utilisateurs')
            ->where('role', 'encadrant')->count();

        return [
            'totalSpecialites'     => $totalSpecialites,
            'totalSoutenances'     => $totalSoutenances,
            'soutenancesTerminees' => $soutenancesTerminees,
            'tauxReussite'         => round($tauxReussite, 1),
            'totalEtudiants'       => $totalEtudiants,
            'totalEncadrants'      => $totalEncadrants,
        ];
    }

    // ── CHARTS ───────────────────────────────

    /**
     * Histogramme : nb de soutenances par spécialité
     */
    private function soutenancesParSpecialite(): array
    {
        $data = DB::table('soutenances')
            ->join('projets_pfe', 'projets_pfe.id', '=', 'soutenances.projet_id')
            ->join('utilisateurs as etu', 'etu.id', '=', 'projets_pfe.etudiant_id')
            ->join('specialites', 'specialites.id', '=', 'etu.specialite_id')
            ->select('specialites.nom', DB::raw('COUNT(*) as total'))
            ->groupBy('specialites.nom')
            ->orderByDesc('total')
            ->get();

        return [
            'labels' => $data->pluck('nom')->values(),
            'values' => $data->pluck('total')->values(),
        ];
    }

    /**
     * Histogramme : nb d'encadrants par spécialité
     */
    private function encadrantsParSpecialite(): array
    {
        $data = DB::table('utilisateurs')
            ->join('specialites', 'specialites.id', '=', 'utilisateurs.specialite_id')
            ->where('utilisateurs.role', 'encadrant')
            ->select('specialites.nom', DB::raw('COUNT(*) as total'))
            ->groupBy('specialites.nom')
            ->orderByDesc('total')
            ->get();

        return [
            'labels' => $data->pluck('nom')->values(),
            'values' => $data->pluck('total')->values(),
        ];
    }

    /**
     * Histogramme : répartition étudiants par spécialité
     */
    private function etudiantsParSpecialite(): array
    {
        $data = DB::table('utilisateurs')
            ->join('specialites', 'specialites.id', '=', 'utilisateurs.specialite_id')
            ->where('utilisateurs.role', 'etudiant')
            ->select('specialites.nom', DB::raw('COUNT(*) as total'))
            ->groupBy('specialites.nom')
            ->orderByDesc('total')
            ->get();

        return [
            'labels' => $data->pluck('nom')->values(),
            'values' => $data->pluck('total')->values(),
        ];
    }

    /**
     * Jauge : taux global de réussite des PFE
     * = résultats admis / total résultats publiés
     */
    private function tauxReussiteGlobal(): array
    {
        $taux = $this->calculerTauxReussiteGlobal();

        $admis   = DB::table('resultats_pfe')->where('decision', 'admis')->where('publie', true)->count();
        $ajournes = DB::table('resultats_pfe')->where('decision', 'ajourne')->where('publie', true)->count();

        return [
            'taux'     => round($taux, 1),
            'admis'    => $admis,
            'ajournes' => $ajournes,
            'labels'   => ['Admis', 'Ajourné'],
            'values'   => [$admis, $ajournes],
        ];
    }

    /**
     * Camembert : taux de soutenances réalisées
     */
    private function soutenancesRealisees(): array
    {
        $total      = DB::table('soutenances')->count();
        $terminees  = DB::table('soutenances')->where('statut', 'termine')->count();
        $planifiees = DB::table('soutenances')->whereNotNull('date_soutenance')->where('statut', '!=', 'termine')->count();
        $enAttente  = max(0, $total - $terminees - $planifiees);

        $taux = $total > 0 ? round(($terminees / $total) * 100, 1) : 0;

        return [
            'taux'      => $taux,
            'labels'    => ['Réalisées', 'Planifiées', 'En attente'],
            'values'    => [$terminees, $planifiees, $enAttente],
        ];
    }

    /**
     * Courbe : PFE finalisés dans les délais (par mois)
     * = comparaison soutenances prévues vs réalisées à temps
     */
    private function pfeFinalisesDelais(): array
    {
        $data = DB::table('soutenances')
            ->whereNotNull('date_soutenance')
            ->select(
                DB::raw("DATE_FORMAT(date_soutenance, '%Y-%m') as mois"),
                DB::raw("COUNT(*) as prevus"),
                DB::raw("SUM(CASE WHEN statut = 'termine' AND date_soutenance <= CURDATE() THEN 1 ELSE 0 END) as realises")
            )
            ->groupBy('mois')
            ->orderBy('mois')
            ->get();

        return [
            'labels'   => $data->pluck('mois')->values(),
            'prevus'   => $data->pluck('prevus')->values(),
            'realises' => $data->pluck('realises')->values(),
        ];
    }

    // ── HELPERS ──────────────────────────────

    private function calculerTauxReussiteGlobal(): float
    {
        $total = DB::table('resultats_pfe')->where('publie', true)->count();
        if ($total === 0) return 0;
        $admis = DB::table('resultats_pfe')->where('decision', 'admis')->where('publie', true)->count();
        return ($admis / $total) * 100;
    }
}