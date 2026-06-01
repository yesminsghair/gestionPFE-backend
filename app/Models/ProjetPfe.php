<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjetPfe extends Model
{
    protected $table    = 'projets_pfe';
    protected $fillable = ['etudiant_id', 'encadrant_id', 'titre', 'description', 'specialite'];

    public function etudiant(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'etudiant_id');
    }

    public function encadrant(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'encadrant_id');
    }

    /**
     * The jury composition group for this project.
     * jury_membres_pfe.projet_id → projets_pfe.id
     */
    public function jury(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(JuryMembrePfe::class, 'projet_id');
    }

    /**
     * The validated defence session for this project (created only after plan validation).
     * Goes through jury: projet → jury_membres_pfe → soutenances
     */
    public function soutenance(): \Illuminate\Database\Eloquent\Relations\HasOneThrough
    {
        return $this->hasOneThrough(
            Soutenance::class,    // final model
            JuryMembrePfe::class, // intermediate model
            'projet_id',          // FK on jury_membres_pfe pointing to projets_pfe
            'jury_id',            // FK on soutenances pointing to jury_membres_pfe
            'id',                 // local key on projets_pfe
            'id'                  // local key on jury_membres_pfe
        );
    }
}