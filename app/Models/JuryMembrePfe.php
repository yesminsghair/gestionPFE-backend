<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * jury_membres_pfe — jury composition group
 * ─────────────────────────────────────────
 * id               bigint PK
 * chef_id          FK → utilisateurs.id   (who created this composition)
 * projet_id        FK → projets_pfe.id    UNIQUE (one composition per project)
 * encadrant_id     FK → utilisateurs.id   nullable
 * president_id     FK → utilisateurs.id   nullable
 * examinateur_id   FK → utilisateurs.id   nullable
 * publie           boolean default false  (true = all 3 members notified)
 * created_at / updated_at
 *
 * Lifecycle:
 *   1. Chef creates row (encadrant_id auto-filled from affectation)
 *   2. Chef sets president_id + examinateur_id
 *   3. Chef publishes → publie=true, notifications sent to president+examinateur
 *   4. Members propose plans (plans_soutenance.jury_id → this id)
 *   5. Chef validates a plan → soutenances row created with jury_id = this id
 */
class JuryMembrePfe extends Model
{
    protected $table    = 'jury_membres_pfe';

    protected $fillable = [
        'chef_id',
        'projet_id',
        'encadrant_id',
        'president_id',
        'examinateur_id',
        'publie',
    ];

    protected $casts = [
        'publie' => 'boolean',
    ];

    // ── Relations ──────────────────────────────────────────────────

    /** Chef de département who created this composition */
    public function chef(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'chef_id');
    }

    /** Project this jury composition belongs to */
    public function projet(): BelongsTo
    {
        return $this->belongsTo(ProjetPfe::class, 'projet_id');
    }

    /** The encadrant member */
    public function encadrant(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'encadrant_id');
    }

    /** The président de jury */
    public function president(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'president_id');
    }

    /** The examinateur */
    public function examinateur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'examinateur_id');
    }

    /**
     * The validated session (soutenance) for this jury group.
     * NULL until chef validates a proposed plan.
     */
    public function soutenance(): HasOne
    {
        return $this->hasOne(Soutenance::class, 'jury_id');
    }

    /**
     * All proposed plans (plans_soutenance) submitted for this jury group.
     */
    public function plans(): HasMany
    {
        return $this->hasMany(PlanSoutenance::class, 'jury_id');
    }

    // ── Helpers ────────────────────────────────────────────────────

    /** True when all 3 roles are filled */
    public function isComplete(): bool
    {
        return $this->encadrant_id && $this->president_id && $this->examinateur_id;
    }

    /** True when composition is published (all members notified) */
    public function isPublished(): bool
    {
        return (bool) $this->publie;
    }

    /**
     * Returns the fonction of a given utilisateur_id within this jury.
     * Returns null if the user is not part of this jury.
     */
    public function fonctionOf(int $userId): ?string
    {
        if ($this->encadrant_id === $userId)   return 'encadrant';
        if ($this->president_id === $userId)   return 'president';
        if ($this->examinateur_id === $userId) return 'examinateur';
        return null;
    }
}