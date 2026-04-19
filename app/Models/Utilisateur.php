<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Utilisateur extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'utilisateurs';

    protected $fillable = [
        'nom',
        'prenom',
        'email',
        'password',
        'matricule',
        'role',
        'etablissement',
        'domaine_expertise',
        'specialite_id',
        'date_affectation',
    ];

    protected $hidden = ['password'];

    // ── Relations ──────────────────────────────────────────────────

    public function specialite()
    {
        return $this->belongsTo(Specialite::class, 'specialite_id');
    }

    /**
     * Un utilisateur a un seul compte (1:1)
     */
    public function compte()
    {
        return $this->hasOne(Compte::class, 'utilisateur_id');
    }

    /**
     * Un encadrant a plusieurs affectations
     */
    public function affectationsEncadrant(): HasMany
    {
        return $this->hasMany(Affectation::class, 'encadrant_id');
    }

    /**
     * Un étudiant a une affectation
     */
    public function affectation(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Affectation::class, 'etudiant_id');
    }

    /**
     * Un enseignant peut être membre de plusieurs jurys
     */
    public function juryMembres(): HasMany
    {
        return $this->hasMany(JuryMembre::class, 'enseignant_id');
    }

    /**
     * Un enseignant peut avoir plusieurs notes
     */
    public function notesJury(): HasMany
    {
        return $this->hasMany(NoteJury::class, 'membre_id');
    }

    /**
     * Un encadrant peut organiser plusieurs réunions
     */
    public function reunionsEncadrant(): HasMany
    {
        return $this->hasMany(Reunion::class, 'encadrant_id');
    }

    /**
     * Un étudiant peut avoir plusieurs réunions
     */
    public function reunionsEtudiant(): HasMany
    {
        return $this->hasMany(Reunion::class, 'etudiant_id');
    }

    /**
     * Un étudiant peut déposer plusieurs livrables
     */
    public function livrables(): HasMany
    {
        return $this->hasMany(Livrable::class, 'etudiant_id');
    }

    // ── Accesseurs pratiques ───────────────────────────────────────

    public function getStatusAttribute(): ?string
    {
        return $this->compte?->status;
    }

    public function getEmailVerifiedAtAttribute(): ?string
    {
        return $this->compte?->email_verified_at;
    }

    public function getEmailVerificationTokenAttribute(): ?string
    {
        return $this->compte?->email_verification_token;
    }

    public function getActifAttribute(): bool
    {
        return (bool) ($this->compte?->actif ?? false);
    }

    // ── Accesseur pour le nom complet ──────────────────────────────
    
    public function getNomCompletAttribute(): string
    {
        return $this->nom . ' ' . $this->prenom;
    }
}