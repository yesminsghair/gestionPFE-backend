<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    protected $table = 'notifications';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'message',
        'lu',
        'created_at',
    ];

    protected $casts = [
        'lu' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'user_id');
    }
}