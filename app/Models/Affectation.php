<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Modèle Affectation — compatible sprints 1/2/3.
 * La table "affectations" est celle du sprint 1/2 enrichie ici par les relations sprint 3.
 *
 * @property string $mode    'manuel' | 'aleatoire' | 'semi'
 * @property string $statut  'en_cours' | 'diffusee'
 *
 * ⚠️  Relations sprint 3 (jury, resultat, suiviPhases, livrables, reunions,
 *     ainsi que les accesseurs progression/phaseActive/estTermine) supposent
 *     que les modèles Jury, Resultat, SuiviEtudiantPhase, Livrable, Reunion
 *     et Phase existent. Si ces modèles ne sont pas encore déployés, ces
 *     méthodes lèveront une exception "Class not found".
 *
 * ⚠️  hasJuryComplet() et hasPresident() exécutent des requêtes supplémentaires
 *     sur jury->membres. Pour éviter le N+1 lors d'un appel en masse, chargez
 *     la relation en amont :  Affectation::with(['jury.membres'])->get()
 */
class Affectation extends Model
{
    protected $table = 'affectations';

    protected $fillable = [
        'chef_id',
        'mode',
        'statut',
        'diffuse_at',
        'etudiant_id',
        'encadrant_id',
        'titre_projet',
        'description',
    ];

    protected $casts = [
        'diffuse_at' => 'datetime',
        // ENUM columns — cast to string so magic-string bugs surface early.
        // Replace with backed Enum classes once PHP 8.1 enums are introduced:
        //   'mode'   => \App\Enums\ModeAffectation::class,
        //   'statut' => \App\Enums\StatutAffectation::class,
        'mode'   => 'string',
        'statut' => 'string',
    ];

    // ── Constantes ENUM ───────────────────────────────────────────────────────
    // Centralise les valeurs autorisées pour éviter les magic strings partout.

    public const MODE_MANUEL    = 'manuel';
    public const MODE_ALEATOIRE = 'aleatoire';
    public const MODE_SEMI      = 'semi';

    public const STATUT_EN_COURS = 'en_cours';
    public const STATUT_DIFFUSEE = 'diffusee';

    // ── Relations sprint 1/2 ─────────────────────────────────────────────────

    /** Chef de département ayant créé l'affectation. */
    public function chef(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'chef_id');
    }

    /** Étudiant affecté. */
    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'etudiant_id');
    }

    /** Encadrant (professeur) affecté. */
    public function encadrant(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'encadrant_id');
    }

    // ── Relations sprint 3 ───────────────────────────────────────────────────

    /** Un projet a un seul jury. */
    public function jury(): HasOne
    {
        return $this->hasOne(Jury::class, 'affectation_id');
    }

    /** Un projet a un seul résultat final. */
    public function resultat(): HasOne
    {
        return $this->hasOne(Resultat::class, 'affectation_id');
    }

    /** Suivi des phases pour cet étudiant/projet. */
    public function suiviPhases(): HasMany
    {
        return $this->hasMany(SuiviEtudiantPhase::class, 'affectation_id');
    }

    /**
     * Livrables déposés par l'étudiant.
     *
     * FIX: utilise affectation_id comme clé étrangère (plus robuste).
     * Si la table livrables ne possède pas encore cette colonne, revenez
     * temporairement à : hasMany(Livrable::class, 'etudiant_id', 'etudiant_id')
     * mais notez que cela retournera les mêmes livrables sur deux affectations
     * successives du même étudiant.
     */
    public function livrables(): HasMany
    {
        return $this->hasMany(Livrable::class, 'affectation_id');
    }

    /**
     * Réunions liées à cette affectation.
     *
     * FIX: utilise affectation_id comme clé étrangère (plus robuste).
     * Même remarque que pour livrables() ci-dessus.
     */
    public function reunions(): HasMany
    {
        return $this->hasMany(Reunion::class, 'affectation_id');
    }

    // ── Accesseurs utiles ────────────────────────────────────────────────────

    /**
     * Vérifie si l'affectation a un jury complet (au moins 2 membres).
     *
     * ⚠️  N+1 : appelez avec with(['jury.membres']) si utilisé sur une collection.
     */
    public function hasJuryComplet(): bool
    {
        if (!$this->relationLoaded('jury')) {
            $this->load('jury.membres');
        }

        return $this->jury !== null
            && $this->jury->membres->count() >= 2;
    }

    /**
     * Vérifie si l'affectation a un président de jury désigné.
     *
     * ⚠️  N+1 : appelez avec with(['jury.membres']) si utilisé sur une collection.
     */
    public function hasPresident(): bool
    {
        if (!$this->relationLoaded('jury')) {
            $this->load('jury.membres');
        }

        return $this->jury !== null
            && $this->jury->membres->where('fonction', 'president')->isNotEmpty();
    }

    /**
     * Calcule la progression globale de l'étudiant (en pourcentage).
     *
     * FIX: le total est désormais calculé à partir des phases réellement suivies
     * par cet étudiant (via suiviPhases), et non de toutes les phases du chef.
     * Cela évite un dénominateur erroné si des phases optionnelles ou d'autres
     * cohortes sont rattachées au même chef.
     */
    public function getProgressionAttribute(): int
    {
        $totalPhases = $this->suiviPhases()->count();

        if ($totalPhases === 0) {
            return 0;
        }

        $phasesValidees = $this->suiviPhases()
            ->where('statut', 'validee')
            ->count();

        return (int) round(($phasesValidees / $totalPhases) * 100);
    }

    /**
     * Récupère la phase active en cours pour cet étudiant.
     */
    public function getPhaseActiveAttribute(): ?Phase
    {
        $suiviActif = $this->suiviPhases()
            ->with('phase')
            ->where('statut', 'en_cours')
            ->first();

        return $suiviActif?->phase;
    }

    /**
     * Vérifie si le projet est terminé (toutes les phases validées).
     *
     * FIX: même correction que getProgressionAttribute — dénominateur basé
     * sur le suivi réel de l'étudiant plutôt que sur Phase::where('chef_id').
     */
    public function getEstTermineAttribute(): bool
    {
        $totalPhases = $this->suiviPhases()->count();

        if ($totalPhases === 0) {
            return false;
        }

        $phasesValidees = $this->suiviPhases()
            ->where('statut', 'validee')
            ->count();

        return $phasesValidees >= $totalPhases;
    }
}