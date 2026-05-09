<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class JuryMembrePfe extends Model {
    protected $table = 'jury_membres_pfe';
    protected $fillable = ['soutenance_id', 'enseignant_id', 'fonction'];
    public function soutenance() { return $this->belongsTo(Soutenance::class, 'soutenance_id'); }
    // Alias conservé pour compatibilité descendante
    public function jury()       { return $this->soutenance(); }
    public function enseignant(){ return $this->belongsTo(Utilisateur::class, 'enseignant_id'); }
}