<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Resultat extends Model
{
    protected $table = 'resultats';

    protected $fillable = [
        'affectation_id',
        'note_finale',
        'mention',
        'decision',
        'publie',
        'publie_le',
    ];

    protected $casts = [
        'note_finale' => 'decimal:2',
        'publie'      => 'boolean',
        'publie_le'   => 'datetime',
    ];

    public function affectation(): BelongsTo
    {
        return $this->belongsTo(Affectation::class, 'affectation_id');
    }
}