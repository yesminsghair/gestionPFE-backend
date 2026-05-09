<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * soutenances  (anciennement jurys_pfe — renommée)
 * ─────────────────────────────────────────────────────────
 * id                  bigint PK
 * projet_id           FK → projets_pfe.id
 * date_soutenance     date nullable
 * heure_debut         time nullable
 * heure_fin           time nullable
 * salle               varchar(100) nullable
 * statut              enum('en_attente','planifie','termine','annule')
 * calendrier_publie   boolean default false
 * created_at / updated_at
 *
 * Migration de renommage :
 *   ALTER TABLE jurys_pfe RENAME TO soutenances;
 *   -- puis pour chaque table enfant :
 *   ALTER TABLE jury_membres_pfe RENAME TO soutenance_membres;
 *   ALTER TABLE soutenance_membres RENAME COLUMN jury_id TO soutenance_id;
 *   ALTER TABLE notes_pfe          RENAME COLUMN jury_id TO soutenance_id;
 *   ALTER TABLE resultats_pfe      RENAME COLUMN jury_id TO soutenance_id;
 */
class Soutenance extends Model
{
    protected $table = 'soutenances';

    protected $fillable = [
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

    public function projet(): BelongsTo
    {
        return $this->belongsTo(ProjetPfe::class, 'projet_id');
    }

    public function membres(): HasMany
    {
        // Table jury_membres_pfe (nom conservé), colonne jury_id renommée en soutenance_id
        return $this->hasMany(JuryMembrePfe::class, 'soutenance_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(NotePfe::class, 'soutenance_id');
    }

    public function resultat(): HasOne
    {
        return $this->hasOne(ResultatPfe::class, 'soutenance_id');
    }

    /** Plans proposés (par jury ou encadrant) liés à cette soutenance */
    public function plans(): HasMany
    {
        return $this->hasMany(PlanSoutenance::class, 'soutenance_id');
    }

    // ── Helper : contrôle de salle ────────────────────────────────

    /**
     * Retourne true si la salle est libre sur le créneau [heureDebut, heureFin).
     * $excludeId = id de la soutenance en cours de modification (à ignorer).
     *
     * Usage dans le contrôleur :
     *   if (!Soutenance::salleLibre($salle, $date, $debut, $fin, $id)) {
     *       return response()->json(['message' => 'Salle déjà réservée.'], 422);
     *   }
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