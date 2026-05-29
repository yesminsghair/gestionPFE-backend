<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotePfe extends Model
{
    protected $table = 'notes_pfe';

    protected $fillable = [
        'soutenance_id',    // FK → soutenances.id  (was jury_id before rename)
        'enseignant_id',
        'note',
        'commentaire',
        'finalise',
    ];

    protected $casts = [
        'note'     => 'decimal:2',
        'finalise' => 'boolean',
    ];

    // ── Relations ──────────────────────────────────────────────────

    public function soutenance()
    {
        return $this->belongsTo(Soutenance::class, 'soutenance_id');
    }

    /** Alias kept for any legacy code that calls ->jury */
    public function jury()
    {
        return $this->soutenance();
    }

    public function enseignant()
    {
        return $this->belongsTo(Utilisateur::class, 'enseignant_id');
    }
}