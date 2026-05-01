<?php 
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class JuryPfe extends Model {
    protected $table = 'jurys_pfe';
    protected $fillable = ['projet_id', 'date_soutenance', 'heure_debut', 'heure_fin', 'salle', 'statut', 'calendrier_publie'];
    protected $casts = ['calendrier_publie' => 'boolean'];
    public function projet()   { return $this->belongsTo(ProjetPfe::class, 'projet_id'); }
    public function membres()  { return $this->hasMany(JuryMembrePfe::class, 'jury_id'); }
    public function notes()    { return $this->hasMany(NotePfe::class, 'jury_id'); }
    public function resultat() { return $this->hasOne(ResultatPfe::class, 'jury_id'); }
}