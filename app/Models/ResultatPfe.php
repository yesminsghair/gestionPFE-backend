<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ResultatPfe extends Model {
    protected $table = 'resultats_pfe';
    protected $fillable = ['jury_id', 'etudiant_id', 'note_finale', 'mention', 'decision', 'publie', 'publie_le'];
    protected $casts = ['note_finale' => 'decimal:2', 'publie' => 'boolean', 'publie_le' => 'datetime'];
    public function jury()     { return $this->belongsTo(JuryPfe::class, 'jury_id'); }
    public function etudiant() { return $this->belongsTo(Utilisateur::class, 'etudiant_id'); }
}