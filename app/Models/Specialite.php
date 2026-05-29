<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Specialite extends Model
{
    protected $table = 'specialites';

    protected $fillable = [
        'nom',
        'code',
        'description',
        'date_creation',
        'capacite_max',
    ];

    protected $casts = [
        'capacite_max'  => 'integer',
        'date_creation' => 'date:Y-m-d',
    ];

    // Table has created_at / updated_at columns — keep timestamps on
    public $timestamps = true;

    public function utilisateurs()
    {
        return $this->hasMany(Utilisateur::class, 'specialite_id');
    }
}