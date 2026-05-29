<?php

namespace App\Http\Controllers;

use App\Models\ResultatPfe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * ArchivageBiblioController
 *
 * Handles the Archives.vue and Bibliopfe.vue endpoints.
 * Extracted from ResultatPfeController to keep concerns separate.
 *
 * Routes (add to api.php):
 *   GET    /resultats-pfe/archives          → archives()
 *   DELETE /resultats-pfe/archives/{date}   → supprimerArchive()
 *   GET    /resultats-pfe/bibliotheque      → bibliotheque()
 */
class ArchivageBiblioController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────
    // ARCHIVES.VUE
    // ─────────────────────────────────────────────────────────────────────

    /**
     * GET /api/resultats-pfe/archives
     * Archived results grouped by archive_le date.
     * Shape: [ { date: "2026-05-10", data: [ { id, nom, matricule, ... } ] } ]
     *
     * FIELD NAMES match Archives.vue exactly:
     *   nom, matricule, projet_titre, encadrant_nom, note_finale, mention, decision
     */
    public function archives(): JsonResponse
    {
        $user  = Auth::user();
        $query = ResultatPfe::with([
                'etudiant',
                'soutenance.projet.encadrant',
            ])
            ->where('archive', true)
            ->orderByDesc('archive_le');

        if ($user->specialite_id) {
            $query->whereHas('etudiant', fn($q) =>
                $q->where('specialite_id', $user->specialite_id)
            );
        }

        $grouped = $query->get()
            ->groupBy(fn($r) => optional($r->archive_le)->format('Y-m-d') ?? 'unknown')
            ->map(fn($group, $date) => [
                'date' => $date,
                'data' => $group->map(fn($r) => [
                    'id'            => $r->id,
                    // Use direct etudiant relation — never null
                    'nom'           => trim(
                        ($r->etudiant?->prenom ?? '') . ' ' .
                        ($r->etudiant?->nom    ?? '')
                    ),
                    'matricule'     => $r->etudiant?->matricule ?? '—',
                    'projet_titre'  => $r->soutenance?->projet?->titre ?? '—',
                    'encadrant_nom' => $r->soutenance?->projet?->encadrant
                        ? trim(
                            ($r->soutenance->projet->encadrant->prenom ?? '') . ' ' .
                            ($r->soutenance->projet->encadrant->nom    ?? '')
                          )
                        : '—',
                    'note_finale'   => $r->note_finale,
                    'mention'       => $r->mention,
                    'decision'      => $r->decision,
                ])->values(),
            ])
            ->values();

        return response()->json($grouped);
    }

    /**
     * DELETE /api/resultats-pfe/archives/{date}
     * Un-archives all results archived on $date (Y-m-d).
     * Directeur only.
     */
    public function supprimerArchive(string $date): JsonResponse
    {
        $user = Auth::user();

        if ($user->specialite_id) {
            return response()->json(
                ['message' => 'Seul le directeur peut supprimer une archive.'],
                403
            );
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return response()->json(['message' => 'Format de date invalide.'], 422);
        }

        $count = ResultatPfe::where('archive', true)
            ->whereDate('archive_le', $date)
            ->update(['archive' => false, 'archive_le' => null]);

        if ($count === 0) {
            return response()->json(['message' => 'Aucune archive trouvée pour cette date.'], 404);
        }

        return response()->json(['message' => "{$count} résultat(s) désarchivé(s)."]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // BIBLIOPFE.VUE
    // ─────────────────────────────────────────────────────────────────────

    /**
     * GET /api/resultats-pfe/bibliotheque
     * All en_biblio=true published results.
     *
     * FIELD NAMES match Bibliopfe.vue exactly:
     *   id, nom, matricule, annee, sujet, encadrant_nom, note, mention
     *
     * NOTE: Bibliopfe.vue was already fixed (in the previous round) to use
     *   etudiant_nom, projet_titre, note_finale — so this endpoint returns
     *   BOTH sets of aliases so either version of the Vue works.
     */
    public function bibliotheque(): JsonResponse
    {
        $resultats = ResultatPfe::with([
                'etudiant',
                'soutenance.projet.encadrant',
            ])
            ->where('en_biblio', true)
            ->where('publie', true)
            ->orderByDesc('note_finale')
            ->get();

        return response()->json($resultats->map(fn($r) => [
            'id'            => $r->id,

            // Direct etudiant — guaranteed to exist
            'etudiant_nom'  => trim(($r->etudiant?->prenom ?? '') . ' ' . ($r->etudiant?->nom ?? '')),
            'nom'           => trim(($r->etudiant?->prenom ?? '') . ' ' . ($r->etudiant?->nom ?? '')), // alias
            'matricule'     => $r->etudiant?->matricule ?? '—',

            // Soutenance chain — safe with null-coalescing
            'projet_titre'  => $r->soutenance?->projet?->titre ?? '—',
            'sujet'         => $r->soutenance?->projet?->titre ?? '—',  // alias
            'encadrant_nom' => $r->soutenance?->projet?->encadrant
                ? trim(
                    ($r->soutenance->projet->encadrant->prenom ?? '') . ' ' .
                    ($r->soutenance->projet->encadrant->nom    ?? '')
                  )
                : '—',

            // Scores
            'note_finale'   => $r->note_finale,
            'note'          => $r->note_finale,  // alias
            'mention'       => $r->mention,

            // Academic year derived from soutenance date
            'annee'         => $this->getAnneeUniversitaire($r),
        ]));
    }

    // ─────────────────────────────────────────────────────────────────────
    // PRIVATE HELPER
    // ─────────────────────────────────────────────────────────────────────

    /** Derive academic year string, e.g. "2025/2026" */
    private function getAnneeUniversitaire(ResultatPfe $r): string
    {
        $date = $r->soutenance?->date_soutenance
            ?? $r->publie_le
            ?? $r->created_at;

        if (!$date) return '—';

        $dt    = \Carbon\Carbon::parse($date);
        $year  = (int) $dt->format('Y');
        $month = (int) $dt->format('m');
        $start = $month >= 9 ? $year : $year - 1;

        return "{$start}/" . ($start + 1);
    }
}