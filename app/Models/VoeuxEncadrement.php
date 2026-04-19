<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VoeuxEncadrement extends Model
{
    protected $table = 'voeux_encadrement';

    protected $fillable = [
        'formulaire_id', 'enseignant_id', 'nbre_etudiants', 'nbre_max_pfe',
        'disponibilite', 'encadrement', 'specialites', 'themes',
        'commentaire', 'cotutelle', 'statut', 'soumis_at',
    ];

    protected $casts = [
        'specialites' => 'array',
        'cotutelle'   => 'boolean',
        'soumis_at'   => 'datetime',
    ];

    // ── Relations ──────────────────────────────────────────────────

    public function formulaire()
    {
        return $this->belongsTo(FormulaireVoeux::class, 'formulaire_id');
    }

    public function enseignant()
    {
        return $this->belongsTo(Utilisateur::class, 'enseignant_id');
    }
}