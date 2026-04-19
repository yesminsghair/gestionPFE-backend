<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JuryMembre extends Model
{
    protected $table = 'jury_membres';

    public $timestamps = false;

    protected $fillable = [
        'jury_id',
        'enseignant_id',
        'fonction',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function jury(): BelongsTo
    {
        return $this->belongsTo(Jury::class, 'jury_id');
    }

    public function enseignant(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'enseignant_id');
    }
}