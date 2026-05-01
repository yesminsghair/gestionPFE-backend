<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
class Conversation extends Model {
    protected $fillable = ['user1_id', 'user2_id'];
    public function messages() { return $this->hasMany(Message::class); }
    public function user1()    { return $this->belongsTo(Utilisateur::class, 'user1_id'); }
    public function user2()    { return $this->belongsTo(Utilisateur::class, 'user2_id'); }
}