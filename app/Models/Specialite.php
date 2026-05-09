<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Specialite extends Model
{
    protected $table = 'specialites';

    protected $fillable = [
        'nom', 
        'code', 
        'description', 
        'date_creation'  // ✅ AJOUTER ce champ
    ];

    // Désactiver les timestamps si votre table n'a pas created_at/updated_at
    public $timestamps = false;

    public function utilisateurs()
    {
        return $this->hasMany(Utilisateur::class, 'specialite_id');
    }
}