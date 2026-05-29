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

    // ── KPI ──────────────────────────────────────────────────────────

    private function getKpi(int $encadrantId): array
    {
        // nb étudiants = rows in projets_pfe where encadrant_id matches
        $nbEtudiants = DB::table('projets_pfe')
            ->where('encadrant_id', $encadrantId)
            ->count();

        // "sujet validé" = projet avec un titre renseigné
        $sujetsValides = DB::table('projets_pfe')
            ->where('encadrant_id', $encadrantId)
            ->whereNotNull('titre')
            ->where('titre', '!=', '')
            ->count();

        $sujetsTotal = $nbEtudiants;

        $tauxValidation = $sujetsTotal > 0
            ? round(($sujetsValides / $sujetsTotal) * 100, 1) : 0;

        $avancementMoyen = $this->calculerAvancementMoyen($encadrantId);
        $tauxReussite    = $this->calculerTauxReussite($encadrantId);

        // réunions confirmées via projets_pfe.etudiant_id
        $totalReunions = DB::table('reunions')
            ->join('projets_pfe', 'projets_pfe.etudiant_id', '=', 'reunions.etudiant_id')
            ->where('projets_pfe.encadrant_id', $encadrantId)
            ->where('reunions.statut', 'confirmee')
            ->count();

        $reunionsMoyennes = $nbEtudiants > 0
            ? round($totalReunions / $nbEtudiants, 1) : 0;

        return [
            'nbEtudiants'      => $nbEtudiants,
            'tauxValidation'   => $tauxValidation,
            'avancementMoyen'  => round($avancementMoyen, 1),
            'tauxReussite'     => round($tauxReussite, 1),
            'reunionsMoyennes' => $reunionsMoyennes,
            'sujetsValides'    => $sujetsValides,
            'sujetsTotal'      => $sujetsTotal,
        ];
    }

    // ── CHARTS ───────────────────────────────────────────────────────

    /**
     * Camembert : projets avec sujet défini vs sans sujet
     */
    private function validationSujets(int $encadrantId): array
    {
        $avecSujet = DB::table('projets_pfe')
            ->where('encadrant_id', $encadrantId)
            ->whereNotNull('titre')
            ->where('titre', '!=', '')
            ->count();

        $sansSujet = DB::table('projets_pfe')
            ->where('encadrant_id', $encadrantId)
            ->where(function ($q) {
                $q->whereNull('titre')->orWhere('titre', '');
            })
            ->count();

        $total = $avecSujet + $sansSujet;
        $taux  = $total > 0 ? round(($avecSujet / $total) * 100, 1) : 0;

        return [
            'taux'   => $taux,
            'labels' => ['Avec sujet', 'Sans sujet'],
            'values' => [$avecSujet, $sansSujet],
        ];
    }

    /**
     * Histogramme : nb de réunions confirmées par étudiant
     */
    private function chargeSuiviEtudiants(int $encadrantId): array
    {
        $data = DB::table('reunions')
            ->join('projets_pfe', 'projets_pfe.etudiant_id', '=', 'reunions.etudiant_id')
            ->join('utilisateurs', 'utilisateurs.id', '=', 'projets_pfe.etudiant_id')
            ->where('projets_pfe.encadrant_id', $encadrantId)
            ->where('reunions.statut', 'confirmee')
            ->select(
                DB::raw("CONCAT(utilisateurs.prenom, ' ', utilisateurs.nom) as name"),
                DB::raw('COUNT(*) as nb_reunions')
            )
            ->groupBy('projets_pfe.etudiant_id', 'name')
            ->orderByDesc('nb_reunions')
            ->get();

        return [
            'labels' => $data->pluck('name')->values(),
            'values' => $data->pluck('nb_reunions')->values(),
        ];
    }

    /**
     * Camembert : statut des livrables des étudiants encadrés
     */
    private function validationRapports(int $encadrantId): array
    {
        $etudiantIds = DB::table('projets_pfe')
            ->where('encadrant_id', $encadrantId)
            ->pluck('etudiant_id');

        $valides   = DB::table('livrables')
            ->whereIn('etudiant_id', $etudiantIds)
            ->where('statut', 'valide')
            ->count();
        $enAttente = DB::table('livrables')
            ->whereIn('etudiant_id', $etudiantIds)
            ->where('statut', 'en_attente')
            ->count();
        $rejetes   = DB::table('livrables')
            ->whereIn('etudiant_id', $etudiantIds)
            ->where('statut', 'rejete')
            ->count();

        $total = $valides + $enAttente + $rejetes;
        $taux  = $total > 0 ? round(($valides / $total) * 100, 1) : 0;

        return [
            'taux'   => $taux,
            'labels' => ['Validés', 'En attente', 'Rejetés'],
            'values' => [$valides, $enAttente, $rejetes],
        ];
    }

    /**
     * Barres : % phases validées par étudiant
     * Uses affectations to get chef_id needed for phases lookup
     */
    private function avancementMoyen(int $encadrantId): array
    {
        // Join affectations via shared etudiant_id
        $affs = DB::table('affectations')
            ->join('projets_pfe', 'projets_pfe.etudiant_id', '=', 'affectations.etudiant_id')
            ->where('projets_pfe.encadrant_id', $encadrantId)
            ->where('affectations.statut', 'diffusee')
            ->select('affectations.*')
            ->get();

        if ($affs->isEmpty()) {
            return ['taux' => 0, 'labels' => [], 'values' => []];
        }

        $details         = [];
        $totalAvancement = 0;

        foreach ($affs as $aff) {
            $totalPhases = DB::table('phases')
                ->where('chef_id', $aff->chef_id)
                ->count();

            $validees = DB::table('suivi_etudiant_phase')
                ->where('affectation_id', $aff->id)
                ->where('statut', 'validee')
                ->count();

            $pct = $totalPhases > 0 ? round(($validees / $totalPhases) * 100) : 0;

            $etu       = DB::table('utilisateurs')->find($aff->etudiant_id);
            $details[] = [
                'nom'      => $etu ? $etu->prenom . ' ' . $etu->nom : 'Étudiant #' . $aff->etudiant_id,
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

    /**
     * Barres : retard par étudiant (1 = en retard, 0 = à jour)
     */
    private function pfeEnRetard(int $encadrantId): array
    {
        $affs = DB::table('affectations')
            ->join('projets_pfe', 'projets_pfe.etudiant_id', '=', 'affectations.etudiant_id')
            ->where('projets_pfe.encadrant_id', $encadrantId)
            ->where('affectations.statut', 'diffusee')
            ->select('affectations.*')
            ->get();

        $labels = [];
        $values = [];

        foreach ($affs as $aff) {
            $etu      = DB::table('utilisateurs')->find($aff->etudiant_id);
            $labels[] = $etu
                ? $etu->prenom . ' ' . $etu->nom
                : 'Étudiant #' . $aff->etudiant_id;

            $retard = DB::table('suivi_etudiant_phase')
                ->join('phases', 'phases.id', '=', 'suivi_etudiant_phase.phase_id')
                ->where('suivi_etudiant_phase.affectation_id', $aff->id)
                ->where('phases.date_fin', '<', now())
                ->where('suivi_etudiant_phase.statut', '!=', 'validee')
                ->count();

            $values[] = $retard > 0 ? 1 : 0;
        }

        $totalEnRetard = array_sum($values);
        $total         = count($values);
        $taux          = $total > 0 ? round(($totalEnRetard / $total) * 100, 1) : 0;

        return [
            'taux'   => $taux,
            'labels' => $labels,
            'values' => $values,
        ];
    }

    /**
     * Jauge : taux de réussite des étudiants encadrés
     */
    private function tauxReussite(int $encadrantId): array
    {
        $taux = $this->calculerTauxReussite($encadrantId);

        $etudiantIds = DB::table('projets_pfe')
            ->where('encadrant_id', $encadrantId)
            ->pluck('etudiant_id');

        $admis = DB::table('resultats_pfe')
            ->whereIn('etudiant_id', $etudiantIds)
            ->where('decision', 'admis')
            ->where('publie', true)
            ->count();

        $ajournes = DB::table('resultats_pfe')
            ->whereIn('etudiant_id', $etudiantIds)
            ->where('decision', 'ajourne')
            ->where('publie', true)
            ->count();

        return [
            'taux'    => round($taux, 1),
            'admis'   => $admis,
            'ajournes' => $ajournes,
            'labels'  => ['Admis', 'Ajourné'],
            'values'  => [$admis, $ajournes],
        ];
    }

    // ── HELPERS ──────────────────────────────────────────────────────

    private function calculerAvancementMoyen(int $encadrantId): float
    {
        $affs = DB::table('affectations')
            ->join('projets_pfe', 'projets_pfe.etudiant_id', '=', 'affectations.etudiant_id')
            ->where('projets_pfe.encadrant_id', $encadrantId)
            ->where('affectations.statut', 'diffusee')
            ->select('affectations.*')
            ->get();

        if ($affs->isEmpty()) return 0.0;

        $total = 0;
        foreach ($affs as $aff) {
            $totalPhases = DB::table('phases')
                ->where('chef_id', $aff->chef_id)
                ->count();
            $validees = DB::table('suivi_etudiant_phase')
                ->where('affectation_id', $aff->id)
                ->where('statut', 'validee')
                ->count();
            $total += $totalPhases > 0 ? ($validees / $totalPhases) * 100 : 0;
        }

        return $total / $affs->count();
    }

    private function calculerTauxReussite(int $encadrantId): float
    {
        $etudiantIds = DB::table('projets_pfe')
            ->where('encadrant_id', $encadrantId)
            ->pluck('etudiant_id');

        $total = DB::table('resultats_pfe')
            ->whereIn('etudiant_id', $etudiantIds)
            ->where('publie', true)
            ->count();

        if ($total === 0) return 0.0;

        $admis = DB::table('resultats_pfe')
            ->whereIn('etudiant_id', $etudiantIds)
            ->where('decision', 'admis')
            ->where('publie', true)
            ->count();

        return ($admis / $total) * 100;
    }
}