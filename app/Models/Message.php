<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
 
class Message extends Model {
    protected $fillable = ['conversation_id', 'expediteur_id', 'contenu', 'lu'];
    protected $casts = ['lu' => 'boolean'];
    public function conversation() { return $this->belongsTo(Conversation::class); }
    public function expediteur()   { return $this->belongsTo(Utilisateur::class, 'expediteur_id'); }
}