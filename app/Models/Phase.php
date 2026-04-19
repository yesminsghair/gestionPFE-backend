<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Phase extends Model
{
    protected $table = 'phases';

    protected $fillable = [
        'chef_id',
        'nom',
        'description',
        'ordre',
        'date_debut',
        'date_fin',
        'coefficient',
        'livrable_obligatoire',
        'active',
        'terminee',
    ];

    protected $casts = [
        'date_debut'           => 'date',
        'date_fin'             => 'date',
        'coefficient'          => 'decimal:2',
        'livrable_obligatoire' => 'boolean',
        'ordre'                => 'integer',
        'active'               => 'boolean',
        'terminee'             => 'boolean',
    ];

    public function chef(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'chef_id');
    }

    public function livrables(): HasMany
    {
        return $this->hasMany(Livrable::class, 'phase_id');
    }

    public function suiviEtudiantPhases(): HasMany
    {
        return $this->hasMany(SuiviEtudiantPhase::class, 'phase_id');
    }
}