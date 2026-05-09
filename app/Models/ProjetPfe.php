<?php 
namespace App\Models;
use App\Models\Soutenance;
use Illuminate\Database\Eloquent\Model;
class ProjetPfe extends Model {
    protected $table = 'projets_pfe';
    protected $fillable = ['etudiant_id', 'encadrant_id', 'titre', 'description', 'specialite'];
    public function etudiant()  { return $this->belongsTo(Utilisateur::class, 'etudiant_id'); }
    public function encadrant() { return $this->belongsTo(Utilisateur::class, 'encadrant_id'); }
    public function jury()      { return $this->hasOne(Soutenance::class, 'projet_id'); }
}