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

    // ── Scopes ─────────────────────────────────────────────────────

    /**
     * Scope: only finalised notes (finalise = true).
     *
     * Prefer this over raw ->where('finalise', true) throughout the codebase
     * so the condition lives in one place and can't diverge.
     *
     * Usage:
     *   NotePfe::finalise()->where('soutenance_id', $id)->first()
     *   $soutenance->notes()->finalise()->where('enseignant_id', $id)->first()
     */
    public function scopeFinalise($query)
    {
        return $query->where('finalise', true);
    }

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
     */
    public function notesGrille(): HasMany
    {
        return $this->hasMany(NoteGrillePfe::class, 'note_pfe_id');
    }
}