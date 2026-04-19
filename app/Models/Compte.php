<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Compte extends Model
{
    protected $table = 'comptes';

    // ✅ Tous les champs liés au compte/accès sont ici
    protected $fillable = [
        'utilisateur_id',
        'email_verification_token',  // Token UUID envoyé par email — null après vérification
        'email_verified_at',         // Date de confirmation email
        'status',                    // pending | active | inactive
        'actif',                     // true = compte utilisable
        'activated_at',              // Date de validation par l'admin
    ];

    protected $casts = [
        'actif'            => 'boolean',
        'email_verified_at'=> 'datetime',
        'activated_at'     => 'datetime',
    ];

    // ── Relation ───────────────────────────────────────────────────

    public function utilisateur()
    {
        return $this->belongsTo(Utilisateur::class, 'utilisateur_id');
    }

    // ── Scopes utiles ──────────────────────────────────────────────

    /** Comptes email-vérifiés mais en attente de validation admin */
    public function scopePending($query)
    {
        return $query->whereNull('email_verification_token')
                     ->where('status', 'pending');
    }

    /** Comptes actifs */
    public function scopeActifs($query)
    {
        return $query->where('status', 'active')->where('actif', true);
    }
}