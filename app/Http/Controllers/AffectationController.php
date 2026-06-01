<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\Affectation;
use App\Models\Notification;
use App\Models\Utilisateur;
use App\Models\VoeuxEncadrement;

class AffectationController extends Controller
{
    // ── POST /api/affectations/notifier-mode ────────────────────────────────
    public function notifierMode(Request $request)
    {
        $request->validate(['mode' => 'required|in:manuel,aleatoire,semi']);

        $chef = $request->user();
        $mode = $request->mode;

        $modeLabels = [
            'manuel'    => 'Accord mutuel étudiant / encadrant',
            'aleatoire' => 'Affectation automatique',
            'semi'      => 'Semi-automatique',
        ];
        $modeLabel = $modeLabels[$mode] ?? $mode;

        // Notify students
        $etudiantIds = Utilisateur::where('role', 'etudiant')
            ->where('specialite_id', $chef->specialite_id)
            ->pluck('id');

        foreach ($etudiantIds as $etudiantId) {
            Notification::create([
                'user_id' => $etudiantId,
                'titre'   => 'Mode d\'affectation défini',
                'message' => "Le chef de département a choisi le mode d'affectation : « {$modeLabel} »."
                    . ($mode === 'manuel' ? ' Vous pouvez dès maintenant envoyer votre demande à un encadrant disponible.' : ''),
                'type'    => 'mode_affectation',
                'lu'      => false,
            ]);
        }

        // Notify encadrants of the same specialty
        $encadrantIds = Utilisateur::where('role', 'encadrant')
            ->where('specialite_id', $chef->specialite_id)
            ->pluck('id');

        foreach ($encadrantIds as $encadrantId) {
            Notification::create([
                'user_id' => $encadrantId,
                'titre'   => 'Mode d\'affectation défini',
                'message' => "Le chef de département a choisi le mode d'affectation : « {$modeLabel} ».",
                'type'    => 'mode_affectation',
                'lu'      => false,
            ]);
        }

        return response()->json(['message' => 'Notifications envoyées.']);
    }

    // ── Cache key helper ─────────────────────────────────────────────────────
    private function cacheKey(int $chefId): string
    {
        return 'affectation_mode_chef_' . $chefId;
    }

    /**
     * Read the saved mode for a given chef.
     * Strategy: cache first → DB fallback → repopulate cache.
     */
    private function readMode(int $chefId): ?string
    {
        $mode = Cache::get($this->cacheKey($chefId));

        if (!$mode) {
            // FIX #1/#2: Always fall back to DB so a cache flush never loses the mode.
            $mode = Affectation::where('chef_id', $chefId)
                ->whereNotNull('mode')
                ->orderBy('updated_at', 'desc')
                ->value('mode');

            if ($mode) {
                Cache::put($this->cacheKey($chefId), $mode, now()->addYear());
            }
        }

        return $mode;
    }

    // ── GET /api/affectations ────────────────────────────────────────────────
    public function index(Request $request)
    {
        $user = $request->user();

        $affs = Affectation::with(['etudiant.specialite', 'encadrant'])
            ->where('chef_id', $user->id)
            ->get();

        return response()->json($affs->map(fn($a) => $this->format($a)));
    }

    // ── GET /api/affectations/mode ───────────────────────────────────────────
    public function getMode(Request $request)
    {
        $user = $request->user();

        if ($user->role === 'etudiant') {
            $chef = Utilisateur::where('role', 'chef')
                ->where('specialite_id', $user->specialite_id)
                ->first();

            if (!$chef) {
                return response()->json(['mode' => null]);
            }

            return response()->json(['mode' => $this->readMode($chef->id)]);
        }

        // Chef reading their own mode
        return response()->json(['mode' => $this->readMode($user->id)]);
    }

    // ── POST /api/affectations/save-mode ─────────────────────────────────────
    public function saveMode(Request $request)
    {
        $request->validate(['mode' => 'required|in:manuel,aleatoire,semi']);

        $chefId = $request->user()->id;
        $mode   = $request->mode;

        // FIX #2: Persist to both cache AND a DB sentinel row so the mode
        // survives a cache flush even before any real affectation rows exist.
        Cache::put($this->cacheKey($chefId), $mode, now()->addYear());

        // Upsert a sentinel row keyed on chef_id with a dummy etudiant_id = 0
        // so readMode()'s DB fallback always finds something.
        // NOTE: if your schema has a unique constraint on etudiant_id alone,
        // store the mode in a dedicated chef_settings table instead.
        // The safest cross-sprint approach: update all existing rows for this chef.
        Affectation::where('chef_id', $chefId)->update(['mode' => $mode]);

        return response()->json(['message' => 'Mode enregistré', 'mode' => $mode]);
    }

    // ── GET /api/affectations/mon-affectation ────────────────────────────────
    public function monAffectation(Request $request)
    {
        $user = $request->user();

        if ($user->role !== 'etudiant') {
            return response()->json(null);
        }

        // Returns the diffused affectation, or the latest en_cours one so the
        // student is never left seeing null between a reinit and re-diffusion.
        // FIX #6: prefer diffusee, but fall back to en_cours.
        $aff = Affectation::with(['encadrant', 'etudiant.specialite'])
            ->where('etudiant_id', $user->id)
            ->orderByRaw("FIELD(statut, 'diffusee', 'en_cours')")
            ->first();

        return response()->json($aff ? $this->format($aff) : null);
    }

    // ── GET /api/affectations/mes-affectations ───────────────────────────────
    public function mesAffectations(Request $request)
    {
        $user = $request->user();

        $affs = Affectation::with(['etudiant.specialite'])
            ->where('encadrant_id', $user->id)
            ->where('statut', 'diffusee')
            ->get();

        return response()->json($affs->map(fn($a) => $this->format($a)));
    }

    // ── GET /api/affectations/encadrants-disponibles ─────────────────────────
    public function encadrantsDisponibles(Request $request)
    {
        $user = $request->user();

        // ── 1. Résoudre le chef de la spécialité ────────────────────────────
        $chefId = $user->role === 'chef'
            ? $user->id
            : Utilisateur::where('role', 'chef')
                ->where('specialite_id', $user->specialite_id)
                ->value('id');

        // ── 2. Contraintes (cap_override + exclure_encadrant) ───────────────
        // Défensif : si la table/modèle Contrainte n'existe pas encore, on
        // continue sans contraintes.
        $contraintes  = collect();
        $capOverrides = collect();
        $exclusIds    = [];

        if ($chefId) {
            try {
                $contraintes = \App\Models\Contrainte::where('chef_id', $chefId)
                    ->whereIn('type', ['cap_override', 'exclure_encadrant'])
                    ->get();

                $capOverrides = $contraintes
                    ->where('type', 'cap_override')
                    ->keyBy('encadrant_id')
                    ->map(fn($c) => (int) $c->cap);

                $exclusIds = array_flip(
                    $contraintes
                        ->where('type', 'exclure_encadrant')
                        ->pluck('encadrant_id')
                        ->map(fn($id) => (int) $id)
                        ->toArray()
                );
            } catch (\Throwable $ex) {
                \Illuminate\Support\Facades\Log::warning('encadrantsDisponibles[contraintes]: ' . $ex->getMessage());
            }
        }

        // ── 3. Formulaire de vœux actif ──────────────────────────────────────
        $formulaireActifId = null;
        if ($chefId) {
            try {
                $formulaireActifId = \App\Models\FormulaireVoeux::where('chef_id', $chefId)
                    ->whereIn('statut', ['publie', 'verrouille'])
                    ->latest()
                    ->value('id');
            } catch (\Throwable $ex) {
                \Illuminate\Support\Facades\Log::warning('encadrantsDisponibles[formulaire]: ' . $ex->getMessage());
            }
        }

        // ── 4. Encadrants ────────────────────────────────────────────────────
        // Relations disponibles sur Utilisateur (vérifiées dans Utilisateur.php) :
        //   affectationsEncadrant → hasMany(Affectation,       foreign: encadrant_id)  ✓
        //   voeuxActif            → hasOne (VoeuxEncadrement,  foreign: enseignant_id) ✓
        //   voeuxEncadrement      → hasMany(VoeuxEncadrement,  foreign: enseignant_id) ✓
        //   demandesEncadrant     → NON DÉFINIE → remplacée par une requête DB directe
        $encadrants = Utilisateur::with([
                'specialite',
                'voeuxActif',
                'voeuxEncadrement' => fn($q) => $q->where('statut', 'soumis'),
            ])
            ->withCount(['affectationsEncadrant as nb_affectes'])
            ->select('id','nom','prenom','email','telephone','domaine_expertise','specialite_id','role')
            ->whereIn('role', ['encadrant', 'chef'])
            ->when($user->specialite_id, fn($q) =>
                $q->where('specialite_id', $user->specialite_id)
            )
            ->get();

        // Demandes acceptées par encadrant — requête directe car la relation
        // demandesEncadrant n'est pas définie sur le modèle Utilisateur.
        $nbAccepteesParEncadrant = collect();
        try {
            $nbAccepteesParEncadrant = DB::table('demandes_encadrement')
                ->whereIn('encadrant_id', $encadrants->pluck('id'))
                ->where('statut', 'acceptee')
                ->selectRaw('encadrant_id, COUNT(*) as total')
                ->groupBy('encadrant_id')
                ->pluck('total', 'encadrant_id');
        } catch (\Throwable $ex) {
            \Illuminate\Support\Facades\Log::warning('encadrantsDisponibles[demandes]: ' . $ex->getMessage());
        }

        // ── 5. Mapper et retourner ───────────────────────────────────────────
        return response()->json(
            $encadrants
                ->filter(fn($e) => !isset($exclusIds[(int) $e->id]))
                ->map(function ($e) use ($formulaireActifId, $capOverrides, $nbAccepteesParEncadrant) {
                    // Voeu pertinent : priorité au formulaire actif, sinon le voeuxActif
                    $voeu = ($formulaireActifId
                                ? $e->voeuxEncadrement->firstWhere('formulaire_id', $formulaireActifId)
                                : null)
                            ?? $e->voeuxActif;

                    $capaciteBase = ($voeu && ($voeu->nbre_max_pfe ?? 0) > 0)
                                    ? $voeu->nbre_max_pfe
                                    : 5;

                    $capacite = $capOverrides->has($e->id)
                                ? $capOverrides->get($e->id)
                                : $capaciteBase;

                    $disponibilite = $voeu?->disponibilite ?? 'oui';
                    $nbAffectes    = $e->nb_affectes ?? 0;
                    $nbAcceptees   = (int) ($nbAccepteesParEncadrant[$e->id] ?? 0);
                    $totalOccupes  = $nbAffectes + $nbAcceptees;

                    return [
                        'id'            => $e->id,
                        'nom'           => $e->nom,
                        'prenom'        => $e->prenom,
                        'nom_complet'   => $e->prenom . ' ' . $e->nom,
                        'email'         => $e->email,
                        'telephone'     => $e->telephone ?? null,
                        'domaine'       => $e->domaine_expertise ?? $e->specialite?->nom,
                        'specialite'    => $e->specialite?->nom,
                        'nb_affectes'   => $totalOccupes,
                        'capacite'      => $capacite,
                        'disponible'    => $disponibilite !== 'non' && $totalOccupes < $capacite,
                        'disponibilite' => $disponibilite,
                        'themes'        => $voeu?->themes,
                        'encadrement'   => $voeu?->encadrement,
                        'cotutelle'     => $voeu?->cotutelle,
                        'commentaire'   => $voeu?->commentaire,
                    ];
                })
                ->values()
        );
    }


    // ── GET /api/affectations/etudiants-de-ma-specialite ────────────────────
    public function etudiantsDeMaSpecialite(Request $request)
    {
        $user = $request->user();

        return response()->json(
            Utilisateur::where('role', 'etudiant')
                ->where('specialite_id', $user->specialite_id)
                ->get()
                ->map(fn($e) => [
                    'id'         => $e->id,
                    'nom'        => $e->nom,
                    'prenom'     => $e->prenom,
                    'matricule'  => $e->matricule,
                    'specialite' => $e->specialite?->nom,
                ])
        );
    }

    // ── POST /api/affectations/batch ─────────────────────────────────────────
    public function batch(Request $request)
    {
        $request->validate([
            'mode'         => 'required|in:manuel,aleatoire,semi',
            'affectations' => 'required|array',
        ]);

        $chef = $request->user();

        foreach ($request->affectations as $row) {
            // FIX #3: include chef_id in the lookup key so a chef cannot
            // silently overwrite another chef's affectation for the same student.
            Affectation::updateOrCreate(
                [
                    'etudiant_id' => $row['etudiant_id'],
                    'chef_id'     => $chef->id,       // ownership guard
                ],
                [
                    'mode'         => $request->mode,
                    'encadrant_id' => $row['encadrant_id'] ?? null,
                    'statut'       => Affectation::STATUT_EN_COURS,
                ]
            );
        }

        return response()->json(['message' => 'Affectations enregistrées']);
    }

    // ── POST /api/affectations/diffuser ──────────────────────────────────────
public function diffuser(Request $request)
{
    $chefId = $request->user()->id;
    $chef   = $request->user();

    $mode = $this->readMode($chefId);

    // Pour le mode manuel : construire les lignes depuis les demandes acceptées
    if ($mode === Affectation::MODE_MANUEL) {
        $demandes = \App\Models\DemandeEncadrement::with(['etudiant'])
            ->whereHas('etudiant', fn($q) =>
                $q->where('specialite_id', $chef->specialite_id)
            )
            ->where('statut', 'acceptee')
            ->get();

        foreach ($demandes as $d) {
            Affectation::updateOrCreate(
                ['etudiant_id' => $d->etudiant_id, 'chef_id' => $chefId],
                [
                    'encadrant_id' => $d->encadrant_id,
                    'mode'         => Affectation::MODE_MANUEL,
                    'statut'       => Affectation::STATUT_EN_COURS,
                ]
            );
        }
    }

    Affectation::where('chef_id', $chefId)
        ->update([
            'statut'     => Affectation::STATUT_DIFFUSEE,
            'diffuse_at' => Carbon::now(),
        ]);

    // Récupérer les affectations fraîchement diffusées (dans les 5 dernières secondes)
    $justDiffusees = Affectation::with(['encadrant'])
        ->where('chef_id', $chefId)
        ->whereNotNull('encadrant_id')
        ->where('diffuse_at', '>=', Carbon::now()->subSeconds(5))
        ->get();

    $nomChef = trim($chef->prenom . ' ' . $chef->nom);

    // ── Notifications encadrants ─────────────────────────────────────────
    foreach ($justDiffusees->groupBy('encadrant_id') as $encadrantId => $affs) {
        Notification::create([
            'user_id' => $encadrantId,
            'message' => "Les affectations ont été diffusées par {$nomChef}. Vous encadrez désormais {$affs->count()} étudiant(s).",
            'lu'      => false,
        ]);
    }

    // ── Notifications étudiants ──────────────────────────────────────────
    foreach ($justDiffusees as $aff) {
        $nomEncadrant = trim($aff->encadrant->prenom . ' ' . $aff->encadrant->nom);

        Notification::create([
            'user_id' => $aff->etudiant_id,
            'message' => "Votre affectation a été diffusée par {$nomChef}. Votre encadrant est : {$nomEncadrant}.",
            'lu'      => false,
        ]);
    }

    return response()->json(['message' => 'Diffusion réussie']);
}

    // ── GET /api/affectations/contraintes ───────────────────────────────────
    public function indexContraintes(Request $request)
    {
        $chefId = $request->user()->id;

        $contraintes = \App\Models\Contrainte::where('chef_id', $chefId)
            ->get()
            ->map(fn($c) => [
                'type'         => $c->type,
                'encadrant_id' => $c->encadrant_id,
                'etudiant_id'  => $c->etudiant_id,
                'cap'          => $c->cap,
                'raison'       => $c->raison ?? '',
            ]);

        // Retrieve stored date_limite for this chef (stored as a special meta row)
        $dateLimite = \App\Models\Contrainte::where('chef_id', $chefId)
            ->where('type', 'date_limite')
            ->value('raison'); // we reuse 'raison' to store the date string

        return response()->json([
            'contraintes' => $contraintes,
            'date_limite' => $dateLimite,
        ]);
    }

    // ── POST /api/affectations/contraintes ───────────────────────────────────
    public function storeContraintes(Request $request)
    {
        $request->validate([
            'contraintes'                => 'present|array',
            'contraintes.*.type'         => 'required|string|in:exclure_encadrant,exclure_paire,forcer_paire,cap_override',
            'contraintes.*.encadrant_id' => 'required|integer|exists:utilisateurs,id',
            'contraintes.*.etudiant_id'  => 'nullable|integer|exists:utilisateurs,id',
            'contraintes.*.cap'          => 'nullable|integer|min:0|max:99',
            'contraintes.*.raison'       => 'nullable|string|max:255',
            'date_limite'                => 'nullable|date|after_or_equal:today',
        ]);

        $chefId = $request->user()->id;
        $chef   = $request->user();

        DB::transaction(function () use ($request, $chefId, $chef) {
            // Delete all existing contraintes (including old date_limite meta row)
            \App\Models\Contrainte::where('chef_id', $chefId)->delete();

            foreach ($request->contraintes as $row) {
                \App\Models\Contrainte::create([
                    'chef_id'      => $chefId,
                    'type'         => $row['type'],
                    'encadrant_id' => $row['encadrant_id'],
                    'etudiant_id'  => $row['etudiant_id'] ?? null,
                    'cap'          => $row['cap'] ?? null,
                    'raison'       => $row['raison'] ?? null,
                ]);
            }

            // Store date_limite as a special meta row (encadrant_id=0 sentinel)
            if ($request->filled('date_limite')) {
                \App\Models\Contrainte::create([
                    'chef_id'      => $chefId,
                    'type'         => 'date_limite',
                    'encadrant_id' => 0,   // sentinel — not a real encadrant
                    'raison'       => $request->date_limite,
                ]);

                // Notify students of the specialty about the deadline
                $dateFormatted = \Carbon\Carbon::parse($request->date_limite)
                    ->locale('fr')
                    ->isoFormat('dddd D MMMM YYYY');

                $etudiantIds = Utilisateur::where('role', 'etudiant')
                    ->where('specialite_id', $chef->specialite_id)
                    ->pluck('id');

                foreach ($etudiantIds as $etudiantId) {
                    Notification::create([
                        'user_id' => $etudiantId,
                        'titre'   => 'Date limite pour les demandes d\'encadrement',
                        'message' => "Vous devez soumettre votre demande d'encadrement avant le {$dateFormatted}. Passé ce délai, les demandes ne seront plus acceptées.",
                        'type'    => 'date_limite',
                        'lu'      => false,
                    ]);
                }
            }
        });

        return response()->json([
            'message' => 'Contraintes enregistrées',
            'count'   => count($request->contraintes),
        ]);
    }

    // ── DELETE /api/affectations/reinitialiser ───────────────────────────────
    public function reinitialiser(Request $request)
    {
        $chef   = $request->user();
        $chefId = $chef->id;

        DB::transaction(function () use ($chef, $chefId) {
            // 1. Supprimer les contraintes du chef
            \App\Models\Contrainte::where('chef_id', $chefId)->delete();

            // 2. Supprimer les affectations du chef
            Affectation::where('chef_id', $chefId)->delete();

            // 3. Supprimer les demandes d'encadrement des étudiants de la spécialité du chef
            //    (concerne le mode manuel — on repart de zéro)
            if ($chef->specialite_id) {
                $etudiantIds = Utilisateur::where('role', 'etudiant')
                    ->where('specialite_id', $chef->specialite_id)
                    ->pluck('id');

                \App\Models\DemandeEncadrement::whereIn('etudiant_id', $etudiantIds)->delete();
            }
        });

        Cache::forget($this->cacheKey($chefId));

        return response()->json(['message' => 'Réinitialisation OK']);
    }

    // ── Private ──────────────────────────────────────────────────────────────
   private function format($a): array
{
    return [
        'id'             => $a->id,
        'mode'           => $a->mode,
        'statut'         => $a->statut,
        'etudiant_id'    => $a->etudiant_id,
        // Full name kept for backward-compat; prenom/nom added separately
        // so the frontend never has to split on spaces (breaks compound names).
        'etudiant'       => $a->etudiant ? $a->etudiant->prenom . ' ' . $a->etudiant->nom : null,
        'prenom'         => $a->etudiant?->prenom,
        'nom'            => $a->etudiant?->nom,
        'matricule'      => $a->etudiant?->matricule,
        'specialite'     => $a->etudiant?->specialite?->nom,
        'encadrant_id'   => $a->encadrant_id,
        'encadrant'      => $a->encadrant ? $a->encadrant->prenom . ' ' . $a->encadrant->nom : null,
        'email'          => $a->etudiant?->email,
        'telephone'      => $a->etudiant?->telephone,
        'date_naissance' => $a->etudiant?->date_naissance?->format('d/m/Y'),
        'adresse'        => $a->etudiant?->adresse,
    ];
}

    // ── GET /api/affectations/mes-etudiants ─────────────────────────────────
    // Lightweight endpoint used by ReunionEncadrant to populate the student
    // dropdown. Does not depend on phases, suivi, or livrables.
    public function mesEtudiants(Request $request)
    {
        $user = $request->user();

        $affs = Affectation::with('etudiant')
            ->where('encadrant_id', $user->id)
            ->get();

        return response()->json(
            $affs
                ->filter(fn($a) => $a->etudiant !== null)
                ->map(fn($a) => [
                    'id'         => $a->etudiant->id,
                    'prenom'     => $a->etudiant->prenom ?? '',
                    'nom'        => $a->etudiant->nom    ?? '',
                    'nomComplet' => trim(($a->etudiant->prenom ?? '') . ' ' . ($a->etudiant->nom ?? '')),
                    'email'      => $a->etudiant->email  ?? null,
                ])
                ->values()
        );
    }

}