<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * plans_soutenance — proposed defence slot by a jury member
 * ─────────────────────────────────────────────────────────
 * id               bigint PK
 * jury_id          FK → jury_membres_pfe.id  (which jury group this concerns)
 * proposant_id     FK → utilisateurs.id       (who proposed)
 * fonction         varchar(30)               (what is their role: encadrant|president|examinateur)
 * date             date
 * heure_debut      time
 * heure_fin        time                       (fixes the durée — locked if plan validated)
 * salle            varchar(100)
 * statut           enum: en_attente | approuve | rejete
 * motif_rejet      text nullable
 * date_traitement  timestamp nullable         (when chef validated or rejected)
 * created_at / updated_at
 *
 * Lifecycle:
 *   en_attente → member submitted, waiting for chef
 *   approuve   → chef validated → triggers soutenance row creation
 *   rejete     → chef rejected  → member can delete and re-propose
 */
class PlanSoutenance extends Model
{
    protected $table = 'plans_soutenance';

    protected $fillable = [
        'jury_id',
        'proposant_id',
        'fonction',
        'statut',
        'date',
        'heure_debut',
        'heure_fin',
        'salle',
        'motif_rejet',
        'date_traitement',
    ];

    protected $casts = [
        'date'            => 'date:Y-m-d',
        'date_traitement' => 'datetime',
    ];

    // ── Relations ────────────────────────────────────────────────

    /**
     * The jury composition group this plan concerns.
     */
    public function jury(): BelongsTo
    {
        return $this->belongsTo(JuryMembrePfe::class, 'jury_id');
    }

    /**
     * The member (encadrant, president, or examinateur) who proposed this slot.
     */
    public function proposant(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'proposant_id');
    }
}