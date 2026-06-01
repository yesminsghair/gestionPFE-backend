<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Modèle Affectation — compatible sprints 1/2/3.
 *
 * @property string $mode    'manuel' | 'aleatoire' | 'semi'
 * @property string $statut  'en_cours' | 'diffusee'
 *
 * ⚠️  Relations sprint 3 (jury, resultat, suiviPhases, livrables, reunions)
 *     supposent que les modèles correspondants existent.
 *
 * ⚠️  hasJuryComplet() et hasPresident() exécutent des requêtes sur
 *     jury->membres. Chargez en amont : Affectation::with(['jury.membres'])->get()
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
        'mode'       => 'string',
        'statut'     => 'string',
    ];

    // ── Constantes ENUM ───────────────────────────────────────────────────────

    public const MODE_MANUEL    = 'manuel';
    public const MODE_ALEATOIRE = 'aleatoire';
    public const MODE_SEMI      = 'semi';

    public const STATUT_EN_COURS = 'en_cours';
    public const STATUT_DIFFUSEE = 'diffusee';

    // ── Relations sprint 1/2 ─────────────────────────────────────────────────

    public function chef(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'chef_id');
    }

    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'etudiant_id');
    }

    public function encadrant(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'encadrant_id');
    }

    // ── Relations sprint 3 ───────────────────────────────────────────────────

    public function jury(): HasOne
    {
        return $this->hasOne(Jury::class, 'affectation_id');
    }

    public function resultat(): HasOne
    {
        return $this->hasOne(Resultat::class, 'affectation_id');
    }

    public function suiviPhases(): HasMany
    {
        return $this->hasMany(SuiviEtudiantPhase::class, 'affectation_id');
    }

    public function livrables(): HasMany
    {
        return $this->hasMany(Livrable::class, 'affectation_id');
    }

    /**
     * Réunions liées à cette affectation.
     *
     * FIX: la table `reunions` ne possède pas de colonne `affectation_id`
     * (ce n'est pas dans Reunion::$fillable et le contrôleur ne la renseigne pas).
     * On scope donc sur la paire (encadrant_id, etudiant_id) qui représente
     * de manière unique la relation encadrant↔étudiant dans ce projet.
     *
     * Si vous ajoutez plus tard une colonne `affectation_id` à la migration
     * `reunions` et que vous la renseignez dans ReunionController::store(),
     * remplacez ce corps par :
     *   return $this->hasMany(Reunion::class, 'affectation_id');
     */
    public function reunions(): HasMany
    {
        return $this->hasMany(Reunion::class, 'encadrant_id', 'encadrant_id')
                    ->where('etudiant_id', $this->etudiant_id);
    }

    // ── Accesseurs utiles ────────────────────────────────────────────────────

    /**
     * Jury complet = au moins 2 membres.
     * ⚠️  Charger with(['jury.membres']) pour éviter le N+1.
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
     * Vérifie si un président de jury est désigné.
     * ⚠️  Charger with(['jury.membres']) pour éviter le N+1.
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
     * Progression globale de l'étudiant (0–100).
     * Basée sur les phases réellement suivies (suiviPhases), pas sur toutes
     * les phases du chef — évite un dénominateur faux pour des cohortes mixtes.
     */
    public function getProgressionAttribute(): int
    {
        $total = $this->suiviPhases()->count();

        if ($total === 0) {
            return 0;
        }

        $validees = $this->suiviPhases()->where('statut', 'validee')->count();

        return (int) round(($validees / $total) * 100);
    }

    /**
     * Phase actuellement en cours pour cet étudiant.
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
     * Vrai si toutes les phases sont validées.
     */
    public function getEstTermineAttribute(): bool
    {
        $total = $this->suiviPhases()->count();

        if ($total === 0) {
            return false;
        }

        return $this->suiviPhases()->where('statut', 'validee')->count() >= $total;
    }
}