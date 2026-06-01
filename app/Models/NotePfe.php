<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotePfe extends Model
{
    protected $table = 'notes_pfe';

    protected $fillable = [
        'soutenance_id',
        'enseignant_id',
        'note',
        'commentaire',
        'finalise',
    ];

    protected $casts = [
        'note'     => 'decimal:2',
        'finalise' => 'boolean',
    ];

    // ── Relations ──────────────────────────────────────────────────

    public function soutenance(): BelongsTo
    {
        return $this->belongsTo(Soutenance::class, 'soutenance_id');
    }

    /** Alias kept for any legacy code that calls ->jury */
    public function jury(): BelongsTo
    {
        return $this->soutenance();
    }

    public function enseignant(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'enseignant_id');
    }

    /**
     * Per-critère breakdown rows stored in notes_grille_pfe.
     * No dedicated Eloquent model exists for that table, so we use a
     * HasMany through DB::table in the controller; this relation is
     * provided for convenience / eager-loading contexts if ever needed.
     */
    public function notesGrille(): HasMany
    {
        // Pivot table: notes_grille_pfe  (note_pfe_id FK → this model)
        // No Eloquent model → use a plain HasMany on the raw table name.
        return $this->hasMany(NoteGrillePfe::class, 'note_pfe_id');
    }
}