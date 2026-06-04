<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Contrainte extends Model
{
    protected $table = 'contraintes';

    protected $fillable = [
        'chef_id',
        'type',
        'encadrant_id',
        'etudiant_id',
        'cap',
        'raison',
    ];

    protected $casts = [
        'encadrant_id' => 'integer',
        'etudiant_id'  => 'integer',
        'cap'          => 'integer',
    ];
}