<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CategorieGrille extends Model
{
    protected $table = 'categories_grille';

    protected $fillable = [
        'grille_id',
        'nom',
        'bareme_max',
        'position',
    ];

    protected $casts = [
        'bareme_max' => 'decimal:2',
        'position'   => 'integer',
    ];

    public function grille(): BelongsTo
    {
        return $this->belongsTo(GrilleEvaluation::class, 'grille_id');
    }

    public function criteres(): HasMany
    {
        return $this->hasMany(CritereEvaluation::class, 'categorie_id')->orderBy('position');
    }
}