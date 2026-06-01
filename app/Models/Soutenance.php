<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * soutenances — validated defence session
 * ────────────────────────────────────────────────────────
 * id                  bigint PK
 * jury_id             FK → jury_membres_pfe.id  (nullable)
 * projet_id           FK → projets_pfe.id        (kept for notes/resultat scoping)
 * date_soutenance     date       (LOCKED — comes from validated plan, cannot be changed)
 * heure_debut         time       (LOCKED — comes from validated plan, cannot be changed)
 * heure_fin           time       (LOCKED — duration fixed by proposant)
 * salle               varchar    (EDITABLE by chef after creation)
 * statut              varchar(30)
 *                       en_attente → session created from validated plan
 *                       publie     → chef published the calendar
 *                       termine    → jury/president submitted evaluation
 * calendrier_publie   boolean default false
 * created_at / updated_at
 *
 * A soutenance row is created ONLY when chef validates a plan (plans_soutenance).
 * date + heure_debut + heure_fin are locked at that point.
 * Only salle can be updated after creation.
 */
class Soutenance extends Model
{
    protected $table = 'soutenances';

    protected $fillable = [
        'jury_id',
        'projet_id',
        'date_soutenance',
        'heure_debut',
        'heure_fin',
        'salle',
        'statut',
        'calendrier_publie',
    ];

    protected $casts = [
        'calendrier_publie' => 'boolean',
    ];

    // ── Relations ─────────────────────────────────────────────────

    /**
     * The jury composition group this session belongs to.
     */
    public function jury(): BelongsTo
    {
        return $this->belongsTo(JuryMembrePfe::class, 'jury_id');
    }

    /**
     * The project (kept for backward compat with notes + resultat).
     */
    public function projet(): BelongsTo
    {
        return $this->belongsTo(ProjetPfe::class, 'projet_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(NotePfe::class, 'soutenance_id');
    }

    public function resultat(): HasOne
    {
        return $this->hasOne(ResultatPfe::class, 'soutenance_id');
    }

    // ── Salle conflict helper ─────────────────────────────────────

    /**
     * Returns true if the salle is free for the given slot.
     * $excludeId = soutenance id to ignore (for updates).
     */
    public static function salleLibre(
        string $salle,
        string $date,
        string $heureDebut,
        string $heureFin,
        ?int   $excludeId = null
    ): bool {
        $toMin = static fn(string $t): int =>
            (int) substr($t, 0, 2) * 60 + (int) substr($t, 3, 2);

        return ! static::where('salle', $salle)
            ->where('date_soutenance', $date)
            ->when($excludeId, fn($q) => $q->where('id', '!=', $excludeId))
            ->whereNotNull('heure_debut')
            ->whereNotNull('heure_fin')
            ->get()
            ->contains(fn($s) =>
                $toMin($heureDebut) < $toMin($s->heure_fin) &&
                $toMin($s->heure_debut) < $toMin($heureFin)
            );
    }
}