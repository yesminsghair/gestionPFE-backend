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
                'soutenancesParSpecialite' => $this->soutenancesParSpecialite(),
                'encadrantsParSpecialite'  => $this->encadrantsParSpecialite(),
                'etudiantsParSpecialite'   => $this->etudiantsParSpecialite(),
                'tauxReussiteGlobal'       => $this->tauxReussiteGlobal(),
                'soutenancesRealisees'     => $this->soutenancesRealisees(),
                'pfeFinalisesDelais'       => $this->pfeFinalisesDelais(),
            ],
        ]);
    }

    // ── KPI ──────────────────────────────────────────────────────────

    private function getKpi(): array
    {
        $totalSpecialites = DB::table('specialites')->count();

        // BUG FIX: count distinct projects that have a soutenance row,
        // not the soutenances table directly (1 soutenance per project guaranteed
        // by the unique index on soutenances.projet_id).
        $totalSoutenances = DB::table('soutenances')->count();

        // BUG FIX: 'termine' is the correct completed statut per DDL default values.
        // 'publie' means calendar published (scheduled), NOT completed.
        $soutenancesTerminees = DB::table('soutenances')
            ->where('statut', 'termine')
            ->count();

        $tauxReussite = $this->calculerTauxReussiteGlobal();

        // BUG FIX: utilisateurs.role = 'etudiant' / 'encadrant' confirmed by
        // Utilisateur model fillable and ChefDashboardController usage.
        $totalEtudiants = DB::table('utilisateurs')
            ->where('role', 'etudiant')
            ->count();

        $totalEncadrants = DB::table('utilisateurs')
            ->where('role', 'encadrant')
            ->count();

        return [
            'totalSpecialites'     => $totalSpecialites,
            'totalSoutenances'     => $totalSoutenances,
            'soutenancesTerminees' => $soutenancesTerminees,
            'tauxReussite'         => round($tauxReussite, 1),
            'totalEtudiants'       => $totalEtudiants,
            'totalEncadrants'      => $totalEncadrants,
        ];
    }

    // ── CHARTS ───────────────────────────────────────────────────────

    /**
     * Histogramme : nb de soutenances par spécialité.
     *
     * BUG FIX: soutenances has a direct projet_id FK (confirmed by DDL unique index
     * jurys_pfe_projet_id_unique on soutenances.projet_id), so the join chain is:
     *   soutenances.projet_id → projets_pfe.id
     *   projets_pfe.etudiant_id → utilisateurs.id (role = etudiant)
     *   utilisateurs.specialite_id → specialites.id
     *
     * The original query was correct structurally but was joining utilisateurs
     * without the role filter, which could double-count if an encadrant shares
     * a specialite_id. Added explicit role = 'etudiant' guard.
     */
    private function soutenancesParSpecialite(): array
    {
        $data = DB::table('soutenances')
            ->join('projets_pfe', 'projets_pfe.id', '=', 'soutenances.projet_id')
            ->join('utilisateurs as etu', 'etu.id', '=', 'projets_pfe.etudiant_id')
            ->join('specialites', 'specialites.id', '=', 'etu.specialite_id')
            ->where('etu.role', 'etudiant')
            ->select('specialites.nom', DB::raw('COUNT(*) as total'))
            ->groupBy('specialites.id', 'specialites.nom')
            ->orderByDesc('total')
            ->get();

        return [
            'labels' => $data->pluck('nom')->values(),
            'values' => $data->pluck('total')->values(),
        ];
    }

    /**
     * Histogramme : nb d'encadrants par spécialité.
     *
     * No bug — encadrants have specialite_id directly on utilisateurs.
     * Added groupBy specialites.id for correctness (avoid duplicates if two
     * specialités share the same nom).
     */
    private function encadrantsParSpecialite(): array
    {
        $data = DB::table('utilisateurs')
            ->join('specialites', 'specialites.id', '=', 'utilisateurs.specialite_id')
            ->where('utilisateurs.role', 'encadrant')
            ->select('specialites.nom', DB::raw('COUNT(*) as total'))
            ->groupBy('specialites.id', 'specialites.nom')
            ->orderByDesc('total')
            ->get();

        return [
            'labels' => $data->pluck('nom')->values(),
            'values' => $data->pluck('total')->values(),
        ];
    }

    /**
     * Histogramme : répartition étudiants par spécialité.
     *
     * Same fix as encadrantsParSpecialite — added specialites.id to groupBy.
     */
    private function etudiantsParSpecialite(): array
    {
        $data = DB::table('utilisateurs')
            ->join('specialites', 'specialites.id', '=', 'utilisateurs.specialite_id')
            ->where('utilisateurs.role', 'etudiant')
            ->select('specialites.nom', DB::raw('COUNT(*) as total'))
            ->groupBy('specialites.id', 'specialites.nom')
            ->orderByDesc('total')
            ->get();

        return [
            'labels' => $data->pluck('nom')->values(),
            'values' => $data->pluck('total')->values(),
        ];
    }

    /**
     * Jauge : taux global de réussite des PFE.
     *
     * resultats_pfe has etudiant_id and publie boolean — confirmed by ResultatPfe
     * model fillable. decision values 'admis' / 'ajourne' confirmed by model.
     * No structural bug here; kept as-is.
     */
    private function tauxReussiteGlobal(): array
    {
        $taux = $this->calculerTauxReussiteGlobal();

        $admis    = DB::table('resultats_pfe')->where('decision', 'admis')->where('publie', true)->count();
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
     * Camembert : taux de soutenances réalisées vs planifiées vs en attente.
     *
     * BUG FIX (critical): The original code used whereNotNull('date_soutenance')
     * to detect "planifiées". Per the DDL, date_soutenance is set when a plan is
     * validated — but statut drives the actual state:
     *   en_attente = soutenance created, calendar not yet published
     *   publie     = calendar published (= properly scheduled / planifiée)
     *   termine    = defence completed
     *
     * Using whereNotNull('date_soutenance') for "planifiées" was wrong because
     * even 'termine' rows have a date, making enAttente always 0.
     */
    private function soutenancesRealisees(): array
    {
        $total     = DB::table('soutenances')->count();
        $terminees = DB::table('soutenances')->where('statut', 'termine')->count();
        $planifiees = DB::table('soutenances')->where('statut', 'publie')->count();
        $enAttente  = DB::table('soutenances')->where('statut', 'en_attente')->count();

        $taux = $total > 0 ? round(($terminees / $total) * 100, 1) : 0;

        return [
            'taux'   => $taux,
            'labels' => ['Réalisées', 'Planifiées', 'En attente'],
            'values' => [$terminees, $planifiees, $enAttente],
        ];
    }

    /**
     * Courbe : PFE finalisés dans les délais (par mois).
     *
     * BUG FIX (critical): The original SUM() treated any 'termine' soutenance
     * with date_soutenance <= CURDATE() as "réalisé dans les délais". That is
     * always true for past dates, so réalisés ≈ prévus for all past months,
     * making the curve useless.
     *
     * Correct interpretation:
     *   - "Prévus" for a month = soutenances with statut IN ('publie','termine')
     *     whose date_soutenance falls in that month (i.e. officially scheduled).
     *   - "Réalisés dans les délais" = those that reached statut 'termine'
     *     on or before their planned date_soutenance (not after a postponement).
     *     Since date_soutenance is LOCKED at plan validation (per Soutenance model
     *     comment), a 'termine' row whose date is in the past IS on time by
     *     definition — the date never changes.
     *   - "Non réalisés" (still publie past their date) = scheduled but missed.
     *
     * We therefore split per month into:
     *   prevus   = statut IN ('publie','termine') in that month
     *   realises = statut = 'termine' in that month (on-time by locked-date logic)
     *   en_retard = statut = 'publie' AND date_soutenance < TODAY (missed deadline)
     *
     * The frontend line chart receives prevus + realises so it can plot both series.
     */
    private function pfeFinalisesDelais(): array
    {
        $data = DB::table('soutenances')
            ->whereIn('statut', ['publie', 'termine'])
            ->whereNotNull('date_soutenance')
            ->select(
                DB::raw("DATE_FORMAT(date_soutenance, '%Y-%m') as mois"),
                DB::raw("COUNT(*) as prevus"),
                DB::raw("SUM(CASE WHEN statut = 'termine' THEN 1 ELSE 0 END) as realises"),
                DB::raw("SUM(CASE WHEN statut = 'publie' AND date_soutenance < CURDATE() THEN 1 ELSE 0 END) as en_retard")
            )
            ->groupBy('mois')
            ->orderBy('mois')
            ->get();

        return [
            'labels'    => $data->pluck('mois')->values(),
            'prevus'    => $data->pluck('prevus')->values(),
            'realises'  => $data->pluck('realises')->values(),
            'enRetard'  => $data->pluck('en_retard')->values(),
        ];
    }

    // ── HELPERS ──────────────────────────────────────────────────────

    private function calculerTauxReussiteGlobal(): float
    {
        $total = DB::table('resultats_pfe')->where('publie', true)->count();
        if ($total === 0) return 0.0;

        $admis = DB::table('resultats_pfe')
            ->where('decision', 'admis')
            ->where('publie', true)
            ->count();

        return ($admis / $total) * 100;
    }
}