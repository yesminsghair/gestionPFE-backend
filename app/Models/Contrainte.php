<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Contrainte extends Model
{
    protected $table = 'contraintes';

    protected $fillable = ['affectation_id', 'type', 'valeur'];

    public function affectation()
    {
        return $this->belongsTo(Affectation::class, 'affectation_id');
    }
}