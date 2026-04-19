<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Specialite extends Model
{
    protected $table = 'specialites';

    protected $fillable = [
        'nom', 'code', 'description', 'date_creation'
    ];

    public function utilisateurs()
    {
        return $this->hasMany(Utilisateur::class, 'specialite_id');
    }
}