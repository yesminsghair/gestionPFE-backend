<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Livrable extends Model
{
    protected $table = 'livrables';

    protected $fillable = [
        'phase_id',
        'etudiant_id',
        'fichier',
        'file_name',
        'statut',
        'commentaire',
        'verrouille',
        'depose_le',
    ];

    protected $casts = [
        'verrouille' => 'boolean',
        'depose_le'  => 'datetime',
    ];

    public function phase()
    {
        return $this->belongsTo(Phase::class, 'phase_id');
    }

    public function etudiant()
    {
        return $this->belongsTo(Utilisateur::class, 'etudiant_id');
    }
}