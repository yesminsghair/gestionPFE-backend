<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    protected $table = 'notifications';

    public $timestamps = false;  // la table n'a pas de updated_at

    const CREATED_AT = 'created_at'; // Laravel remplit created_at automatiquement
    const UPDATED_AT = null;          // pas de updated_at

    protected $fillable = [
        'user_id',
        'message',
        'lu',
    ];

    protected $casts = [
        'lu'         => 'boolean',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function ($notification) {
            if (empty($notification->created_at)) {
                $notification->created_at = now();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'user_id');
    }
}