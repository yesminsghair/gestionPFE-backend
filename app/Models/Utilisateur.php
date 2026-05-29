<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
        'telephone',           // ← ajouté
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

    public function compte()
    {
        return $this->hasOne(Compte::class, 'utilisateur_id');
    }

    public function affectationsEncadrant(): HasMany
    {
        return $this->hasMany(Affectation::class, 'encadrant_id');
    }

    public function affectation(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Affectation::class, 'etudiant_id');
    }

    /**
     * All voeux submissions by this encadrant/enseignant.
     * Foreign key is enseignant_id (matches voeux_encadrement table).
     */
    public function voeuxEncadrement(): HasMany
    {
        return $this->hasMany(VoeuxEncadrement::class, 'enseignant_id');
    }

    /**
     * The latest submitted voeu for this encadrant — used by
     * AffectationController::encadrantsDisponibles() to read constraints.
     *
     * Uses latestOfMany() (Laravel 8.42+) to get the most recently submitted row.
     * Falls back gracefully to null if no voeu has been submitted yet.
     */
    public function voeuxActif(): HasOne
    {
        return $this->hasOne(VoeuxEncadrement::class, 'enseignant_id')
                    ->where('statut', 'soumis')
                    ->latestOfMany('soumis_at');
    }

    public function juryMembres(): HasMany
    {
        return $this->hasMany(JuryMembre::class, 'enseignant_id');
    }

    public function notesJury(): HasMany
    {
        return $this->hasMany(NoteJury::class, 'membre_id');
    }

    public function reunionsEncadrant(): HasMany
    {
        return $this->hasMany(Reunion::class, 'encadrant_id');
    }

    public function reunionsEtudiant(): HasMany
    {
        return $this->hasMany(Reunion::class, 'etudiant_id');
    }

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

    public function getNomCompletAttribute(): string
    {
        return $this->nom . ' ' . $this->prenom;
    }
}