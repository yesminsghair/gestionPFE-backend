<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GrilleEvaluation extends Model
{
    protected $table = 'grilles_evaluation';

    protected $fillable = [
        'chef_id',
        'nom',
        'statut',
        'visibilite',
        'publie_le',
        'verrouille_le',
    ];

    protected $casts = [
        'publie_le'     => 'datetime',
        'verrouille_le' => 'datetime',
    ];

    public function chef(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'chef_id');
    }

    public function categories(): HasMany
    {
        return $this->hasMany(CategorieGrille::class, 'grille_id')->orderBy('position');
    }
}