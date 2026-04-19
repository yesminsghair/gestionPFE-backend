<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NoteJury extends Model
{
    protected $table = 'notes_jury';

    protected $fillable = [
        'jury_id',
        'membre_id',
        'note',
        'commentaire',
        'finalise',
    ];

    protected $casts = [
        'note'     => 'decimal:2',
        'finalise' => 'boolean',
    ];

    public function jury(): BelongsTo
    {
        return $this->belongsTo(Jury::class, 'jury_id');
    }

    public function membre(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'membre_id');
    }
}