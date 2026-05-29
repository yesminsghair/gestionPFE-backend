<?php
// ─────────────────────────────────────────────────────────────────────
// App\Models\ResultatPfe
// ─────────────────────────────────────────────────────────────────────
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResultatPfe extends Model
{
    protected $table = 'resultats_pfe';

    protected $fillable = [
        'soutenance_id',   // ← was jury_id
        'etudiant_id',
        'note_finale',
        'mention',
        'decision',
        'publie',
        'publie_le',
        'en_biblio',
        'archive',
        'archive_le',
    ];

    protected $casts = [
        'note_finale' => 'decimal:2',
        'publie'      => 'boolean',
        'publie_le'   => 'datetime',
        'en_biblio'   => 'boolean',
        'archive'     => 'boolean',
        'archive_le'  => 'datetime',
    ];

    // ── Relations ──────────────────────────────────────────────────

    public function soutenance()
    {
        return $this->belongsTo(Soutenance::class, 'soutenance_id');
    }

    /** Alias for any code still calling ->jury() */
    public function jury()
    {
        return $this->soutenance();
    }

    public function etudiant()
    {
        return $this->belongsTo(Utilisateur::class, 'etudiant_id');
    }
}