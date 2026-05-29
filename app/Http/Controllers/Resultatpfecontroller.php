<?php

namespace App\Http\Controllers;

use App\Models\ResultatPfe;
use App\Models\Utilisateur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ResultatPfeController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────
    // RELATIONS TO EAGER-LOAD
    // resultats_pfe has:
    //   - etudiant_id  → utilisateurs (direct FK — always reliable)
    //   - soutenance_id → soutenances → projet_pfe → encadrant (utilisateurs)
    // We load BOTH paths so formatResultat() never returns blank strings.
    // ─────────────────────────────────────────────────────────────────────
    private const WITH = [
        'etudiant',                          // direct: resultats_pfe.etudiant_id
        'soutenance.projet.encadrant',       // for encadrant_nom + projet_titre
        'soutenance.notes',                  // for note_jury / note_encadrant breakdown
    ];

    // ─────────────────────────────────────────────────────────────────────
    // CONSULTERRESULTATFINAL.VUE
    // ─────────────────────────────────────────────────────────────────────

    /**
     * GET /api/resultats-pfe
     * All non-archived results. Chef → own department. Directeur → all.
     */
    public function index(): JsonResponse
    {
        $user  = Auth::user();
        $query = ResultatPfe::with(self::WITH)->where('archive', false);

        // Scope by department via the DIRECT etudiant relation (always present)
        if ($user->specialite_id) {
            $query->whereHas('etudiant', fn($q) =>
                $q->where('specialite_id', $user->specialite_id)
            );
        }

        return response()->json(
            $query->latest()->get()->map(fn($r) => $this->formatResultat($r))
        );
    }

    /**
     * GET /api/resultats-pfe/publies
     * Published, non-archived results (student-facing).
     */
    public function publies(): JsonResponse
    {
        $user  = Auth::user();
        $query = ResultatPfe::with(self::WITH)
            ->where('publie', true)
            ->where('archive', false);

        if ($user->specialite_id) {
            $query->whereHas('etudiant', fn($q) =>
                $q->where('specialite_id', $user->specialite_id)
            );
        }

        return response()->json(
            $query->latest()->get()->map(fn($r) => $this->formatResultat($r))
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // SINGLE-RECORD ACTIONS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * POST /api/resultats-pfe/{id}/decision
     */
    public function decision(int $id, Request $request): JsonResponse
    {
        $resultat = ResultatPfe::findOrFail($id);

        if ($resultat->publie) {
            return response()->json(
                ['message' => "Impossible de modifier la décision d'un résultat déjà publié."],
                422
            );
        }

        $decision = $request->input('decision');
        if (!in_array($decision, ['admis', 'ajourne'])) {
            return response()->json(
                ['message' => 'Décision invalide. Valeurs acceptées : admis, ajourne.'],
                422
            );
        }

        $resultat->update(['decision' => $decision]);

        return response()->json(['message' => 'Décision mise à jour.', 'decision' => $decision]);
    }

    /**
     * POST /api/resultats-pfe/{id}/publier
     */
    public function publier(int $id): JsonResponse
    {
        $resultat = ResultatPfe::findOrFail($id);

        if ($resultat->publie) {
            return response()->json(['message' => 'Résultat déjà publié.'], 422);
        }

        $resultat->update(['publie' => true, 'publie_le' => now()]);

        return response()->json(['message' => 'Résultat publié avec succès.']);
    }

    /**
     * POST /api/resultats-pfe/{id}/archiver
     */
    public function archiver(int $id): JsonResponse
    {
        $resultat = ResultatPfe::findOrFail($id);

        if (!$resultat->publie) {
            return response()->json(
                ['message' => 'Seuls les résultats publiés peuvent être archivés.'],
                422
            );
        }

        if ($resultat->archive) {
            return response()->json(['message' => 'Résultat déjà archivé.'], 422);
        }

        $resultat->update(['archive' => true, 'archive_le' => now()]);

        return response()->json(['message' => 'Résultat archivé avec succès.']);
    }

    /**
     * POST /api/resultats-pfe/{id}/bibliotheque
     */
    public function ajouterBibliotheque(int $id, Request $request): JsonResponse
    {
        $resultat = ResultatPfe::findOrFail($id);

        if (!$resultat->publie) {
            return response()->json(
                ['message' => 'Seuls les résultats publiés peuvent être ajoutés à la bibliothèque.'],
                422
            );
        }

        $resultat->update(['en_biblio' => (bool) $request->input('en_biblio', true)]);

        return response()->json(['message' => 'Bibliothèque mise à jour.']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // BULK ACTIONS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * POST /api/resultats-pfe/publier-tous
     */
    public function publierTous(): JsonResponse
    {
        $user  = Auth::user();
        $query = ResultatPfe::where('publie', false)->where('archive', false);

        if ($user->specialite_id) {
            $query->whereHas('etudiant', fn($q) =>
                $q->where('specialite_id', $user->specialite_id)
            );
        }

        $count = $query->count();

        if ($count === 0) {
            return response()->json(['message' => 'Aucun résultat à publier.'], 422);
        }

        $query->update(['publie' => true, 'publie_le' => now()]);

        return response()->json(['message' => "{$count} résultat(s) publié(s) avec succès."]);
    }

    /**
     * POST /api/resultats-pfe/archiver-tous
     */
    public function archiverTous(): JsonResponse
    {
        $user  = Auth::user();
        $query = ResultatPfe::where('publie', true)->where('archive', false);

        if ($user->specialite_id) {
            $query->whereHas('etudiant', fn($q) =>
                $q->where('specialite_id', $user->specialite_id)
            );
        }

        $count = $query->count();

        if ($count === 0) {
            return response()->json(['message' => 'Aucun résultat à archiver.'], 422);
        }

        $query->update(['archive' => true, 'archive_le' => now()]);

        return response()->json(['message' => "{$count} résultat(s) archivé(s) avec succès."]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Canonical row shape for ConsulterResultatFinal.vue.
     * Uses the direct etudiant relation so data always appears even when
     * the soutenance → projet chain has nulls.
     */
    private function formatResultat(ResultatPfe $r): array
    {
        $etudiant  = $r->etudiant;                         // direct FK — always present
        $projet    = $r->soutenance?->projet;
        $encadrant = $projet?->encadrant;

        // Break down jury vs encadrant notes from notes_pfe
        $noteJury      = null;
        $noteEncadrant = null;

        if ($r->soutenance && $r->soutenance->notes->isNotEmpty()) {
            $encadrantId   = $projet?->encadrant_id;
            $juryNotes     = $r->soutenance->notes->where('enseignant_id', '!=', $encadrantId);
            $encNote       = $r->soutenance->notes->firstWhere('enseignant_id', $encadrantId);
            $noteJury      = $juryNotes->isNotEmpty()
                ? round($juryNotes->avg('note'), 2)
                : null;
            $noteEncadrant = $encNote?->note;
        }

        return [
            'id'             => $r->id,
            'etudiant_id'    => $r->etudiant_id,
            'etudiant_nom'   => trim(($etudiant?->prenom ?? '') . ' ' . ($etudiant?->nom ?? '')),
            'matricule'      => $etudiant?->matricule ?? '—',
            'projet_titre'   => $projet?->titre ?? '—',
            'encadrant_nom'  => $encadrant
                ? trim(($encadrant->prenom ?? '') . ' ' . ($encadrant->nom ?? ''))
                : '—',
            'note_jury'      => $noteJury,
            'note_encadrant' => $noteEncadrant,
            'note_finale'    => $r->note_finale,
            'mention'        => $r->mention,
            'decision'       => $r->decision,
            'publie'         => (bool) $r->publie,
            'publie_le'      => optional($r->publie_le)->format('d/m/Y'),
            'en_biblio'      => (bool) $r->en_biblio,
            'archive'        => (bool) $r->archive,
            'archive_le'     => optional($r->archive_le)->format('d/m/Y'),
            'date_soutenance'=> $r->soutenance?->date_soutenance,
        ];
    }
}