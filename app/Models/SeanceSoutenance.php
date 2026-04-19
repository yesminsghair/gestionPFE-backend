<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeanceSoutenance extends Model
{
    protected $table = 'seances_soutenance';

    protected $fillable = [
        'jury_id',
        'date_seance',
        'salle',
        'statut',
    ];

    protected $casts = [
        'date_seance' => 'datetime',
    ];

    public function jury(): BelongsTo
    {
        return $this->belongsTo(Jury::class, 'jury_id');
    }
}