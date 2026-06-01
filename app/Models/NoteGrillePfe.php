<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lightweight Eloquent model for the notes_grille_pfe pivot table.
 *
 * Table columns:
 *   id, note_pfe_id, critere_id, note, created_at, updated_at
 *
 * This model is intentionally thin — all complex reads are done via
 * DB::table() joins in EvaluationPfeController::loadCategories().
 */
class NoteGrillePfe extends Model
{
    protected $table = 'notes_grille_pfe';

    protected $fillable = [
        'note_pfe_id',
        'critere_id',
        'note',
    ];

    protected $casts = [
        'note' => 'decimal:2',
    ];

    public function notePfe(): BelongsTo
    {
        return $this->belongsTo(NotePfe::class, 'note_pfe_id');
    }

    public function critere(): BelongsTo
    {
        return $this->belongsTo(CritereEvaluation::class, 'critere_id');
    }
}
