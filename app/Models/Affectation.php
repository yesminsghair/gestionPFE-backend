<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Modèle Affectation — compatible sprints 1/2/3.
 * La table "affectations" est celle du sprint 1/2 enrichie ici par les relations sprint 3.
 */
class Affectation extends Model
{
    protected $table = 'affectations';

    protected $fillable = [
        'chef_id',
        'mode',
        'statut',
        'diffuse_at',
        'etudiant_id',
        'encadrant_id',
        'titre_projet',
        'description',
    ];

    protected $casts = [
        'diffuse_at' => 'datetime',
    ];

    // ── Relations sprint 1/2 ───────────────────────────────────────
    
    /**
     * Relation avec le chef de département qui a créé l'affectation
     */
    public function chef(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'chef_id');
    }

    /**
     * Relation avec l'étudiant affecté
     */
    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'etudiant_id');
    }

    /**
     * Relation avec l'encadrant (professeur) affecté
     */
    public function encadrant(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'encadrant_id');
    }

    // ── Relations sprint 3 ─────────────────────────────────────────
    
    /**
     * Un projet a un seul jury
     */
    public function jury(): HasOne
    {
        return $this->hasOne(Jury::class, 'affectation_id');
    }

    /**
     * Un projet a un seul résultat final
     */
    public function resultat(): HasOne
    {
        return $this->hasOne(Resultat::class, 'affectation_id');
    }

    /**
     * Suivi des phases pour cet étudiant/projet
     */
    public function suiviPhases(): HasMany
    {
        return $this->hasMany(SuiviEtudiantPhase::class, 'affectation_id');
    }

    /**
     * Livrables déposés par l'étudiant (via la relation avec la table livrables)
     * Note: Un livrable est lié à un étudiant, pas directement à l'affectation
     */
    public function livrables(): HasMany
    {
        return $this->hasMany(Livrable::class, 'etudiant_id', 'etudiant_id');
    }

    /**
     * Récupérer les réunions liées à cette affectation (via l'étudiant)
     */
    public function reunions(): HasMany
    {
        return $this->hasMany(Reunion::class, 'etudiant_id', 'etudiant_id');
    }

    // ── Accesseurs utiles ──────────────────────────────────────────
    
    /**
     * Vérifie si l'affectation a un jury complet (au moins 2 membres)
     */
    public function hasJuryComplet(): bool
    {
        if (!$this->jury) {
            return false;
        }
        return $this->jury->membres()->count() >= 2;
    }

    /**
     * Vérifie si l'affectation a un président de jury désigné
     */
    public function hasPresident(): bool
    {
        if (!$this->jury) {
            return false;
        }
        return $this->jury->membres()->where('fonction', 'president')->exists();
    }

    /**
     * Calcule la progression globale de l'étudiant (en pourcentage)
     * Basé sur les phases validées
     */
    public function getProgressionAttribute(): int
    {
        $totalPhases = Phase::where('chef_id', $this->chef_id)->count();
        if ($totalPhases === 0) {
            return 0;
        }
        
        $phasesValidees = $this->suiviPhases()
            ->where('statut', 'validee')
            ->count();
            
        return round(($phasesValidees / $totalPhases) * 100);
    }

    /**
     * Récupère la phase active en cours
     */
    public function getPhaseActiveAttribute(): ?Phase
    {
        $suiviActif = $this->suiviPhases()
            ->with('phase')
            ->where('statut', 'en_cours')
            ->first();
            
        return $suiviActif?->phase;
    }

    /**
     * Vérifie si le projet est terminé (toutes les phases validées)
     */
    public function getEstTermineAttribute(): bool
    {
        $totalPhases = Phase::where('chef_id', $this->chef_id)->count();
        $phasesValidees = $this->suiviPhases()
            ->where('statut', 'validee')
            ->count();
            
        return $totalPhases > 0 && $phasesValidees >= $totalPhases;
    }
}