<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reunion extends Model
{
    protected $table = 'reunions';

    protected $fillable = [
        'encadrant_id',
        'etudiant_id',
        'date_reunion',
        'type',
        'statut',
        'lieu',
        'compte_rendu',
        'motif',
    ];

    protected $casts = [
        'date_reunion' => 'datetime',
    ];

    public function encadrant(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'encadrant_id');
    }

    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'etudiant_id');
    }
}