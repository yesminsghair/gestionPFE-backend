<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DemandeEncadrement extends Model
{
    protected $table = 'demandes_encadrement';

    protected $fillable = [
        'numero', 'sujet', 'description', 'statut',
        'date_demande', 'doc_pdf', 'motif_rejet', 'traite_at',
        'etudiant_id', 'encadrant_id',
    ];

    protected $casts = [
        'date_demande' => 'date',
        'traite_at'    => 'datetime',
    ];

    // Auto-génère le numéro à la création
    protected static function booted(): void
    {
        static::creating(function (self $demande) {
            if (!$demande->numero) {
                $year  = now()->year;
                $count = self::whereYear('created_at', $year)->count() + 1;
                $demande->numero = sprintf('DEM-%d-%03d', $year, $count);
            }
            if (!$demande->date_demande) {
                $demande->date_demande = now()->toDateString();
            }
        });
    }

    // ── Relations ──────────────────────────────────────────────────

    public function etudiant()
    {
        return $this->belongsTo(Utilisateur::class, 'etudiant_id');
    }

    public function encadrant()
    {
        return $this->belongsTo(Utilisateur::class, 'encadrant_id');
    }

    // ── Scopes ─────────────────────────────────────────────────────

    public function scopeEnAttente($q)  { return $q->where('statut', 'en_attente'); }
    public function scopeAcceptees($q)  { return $q->where('statut', 'acceptee'); }
    public function scopeRejetees($q)   { return $q->where('statut', 'rejetee'); }
}