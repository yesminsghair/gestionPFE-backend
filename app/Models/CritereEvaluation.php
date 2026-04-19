<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CritereEvaluation extends Model
{
    protected $table = 'criteres_evaluation';

    protected $fillable = [
        'categorie_id',
        'nom',
        'bareme_max',
        'position',
    ];

    protected $casts = [
        'bareme_max' => 'decimal:2',
        'position'   => 'integer',
    ];

    public function categorie(): BelongsTo
    {
        return $this->belongsTo(CategorieGrille::class, 'categorie_id');
    }
}