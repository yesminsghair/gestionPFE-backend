<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SuiviEtudiantPhase extends Model
{
    use HasFactory;

    // ✅ nom exact de ta table (IMPORTANT car pas "plural standard")
    protected $table = 'suivi_etudiant_phase';

    protected $fillable = [
        'affectation_id',
        'phase_id',
        'statut',
        'date_lancement',
        'date_validation',
        'commentaire_encadrant'
    ];

    // 🔗 relation vers affectation
    public function affectation()
    {
        return $this->belongsTo(Affectation::class, 'affectation_id');
    }

    // 🔗 relation vers phase
    public function phase()
    {
        return $this->belongsTo(Phase::class, 'phase_id');
    }
}