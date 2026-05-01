<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class JuryMembrePfe extends Model {
    protected $table = 'jury_membres_pfe';
    protected $fillable = ['jury_id', 'enseignant_id', 'fonction'];
    public function jury()      { return $this->belongsTo(JuryPfe::class, 'jury_id'); }
    public function enseignant(){ return $this->belongsTo(Utilisateur::class, 'enseignant_id'); }
}