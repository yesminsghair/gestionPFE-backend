<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormulaireVoeux extends Model
{
    protected $table = 'formulaires_voeux';

    protected $fillable = [
        'chef_id',
        'titre',
        'date_limite',
        'nb_max_etudiants',
        'message',
        'champs',
        'statut',
        'publie_at',
        'verrouille_at',
    ];

    protected $casts = [
        'champs'        => 'array',
        'date_limite'   => 'date',
        'publie_at'     => 'datetime',
        'verrouille_at' => 'datetime',
    ];

    // ─────────────────────────────
    // RELATIONS
    // ─────────────────────────────

    public function chef()
    {
        return $this->belongsTo(Utilisateur::class, 'chef_id');
    }

    // ALL responses (each teacher submission)
    public function voeux()
    {
        return $this->hasMany(VoeuxEncadrement::class, 'formulaire_id');
    }

    // teachers who submitted a response
    public function enseignantsAyantRepondu()
    {
        return $this->hasManyThrough(
            Utilisateur::class,
            VoeuxEncadrement::class,
            'formulaire_id',
            'id',
            'id',
            'enseignant_id'
        );
    }

    // ─────────────────────────────
    // SCOPES
    // ─────────────────────────────

    public function scopePublies($q)
    {
        return $q->where('statut', 'publie');
    }

    public function scopeBrouillons($q)
    {
        return $q->where('statut', 'brouillon');
    }

    // ─────────────────────────────
    // STATS
    // ─────────────────────────────

    public function getNbReponsesAttribute(): int
    {
        return $this->voeux()->where('statut', 'soumis')->count();
    }

    public function getProgressPctAttribute(): int
    {
        $total = $this->voeux()->count();

        if ($total === 0) {
            return 0;
        }

        return (int) round($this->nb_reponses / $total * 100);
    }
}