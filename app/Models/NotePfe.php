<?php
namespace App\Models;
use App\Models\Soutenance;
use Illuminate\Database\Eloquent\Model;
class NotePfe extends Model {
    protected $table = 'notes_pfe';
    protected $fillable = ['jury_id', 'enseignant_id', 'note', 'commentaire', 'finalise'];
    protected $casts = ['note' => 'decimal:2', 'finalise' => 'boolean'];
    public function jury()      { return $this->belongsTo(Soutenance::class, 'jury_id'); }
    public function enseignant(){ return $this->belongsTo(Utilisateur::class, 'enseignant_id'); }
}