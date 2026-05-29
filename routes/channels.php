<?php

use Illuminate\Support\Facades\Broadcast;

// TEMPORARY: Allow ALL WebSocket connections
Broadcast::channel('conversation.{id}', function ($user, $id) {
    return true;
});

// Change from private to public channel
Broadcast::channel('notifications.{userId}', function ($user, $userId) {
    return true;  // Allow all 
});