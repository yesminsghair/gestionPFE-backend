<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * plans_soutenance
 * ─────────────────────────────────────────────────────────
 * id               bigint PK
 * proposant_id     FK → utilisateurs.id
 * role             enum('jury','encadrant')
 * statut           enum('en_attente','approuve','rejete')
 * soutenance_id    FK → soutenances.id  (nullable — lié à une soutenance existante)
 * date             date
 * heure_debut      time
 * salle            varchar(100)
 * created_at / updated_at
 * ─────────────────────────────────────────────────────────
 * NB : la table creneaux_plan est supprimée.
 *      Chaque ligne plans_soutenance = un créneau proposé,
 *      éventuellement rattaché à une soutenance déjà créée.
 */
class PlanSoutenance extends Model
{
    protected $table = 'plans_soutenance';

    protected $fillable = [
        'proposant_id',
        'role',
        'statut',
        'soutenance_id',
        'date',
        'heure_debut',
        'salle',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
    ];

    // ── Relations ────────────────────────────────────────────────────────────

    /** L'enseignant (jury ou encadrant) qui propose ce créneau */
    public function proposant(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'proposant_id');
    }

    /**
     * La soutenance à laquelle ce plan est rattaché (nullable).
     * NULL = le chef n'a pas encore créé/associé la soutenance correspondante.
     */
    public function soutenance(): BelongsTo
    {
        return $this->belongsTo(Soutenance::class, 'soutenance_id');
    }
}