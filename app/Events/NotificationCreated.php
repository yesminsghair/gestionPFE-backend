<?php

namespace App\Events;

use App\Models\Notification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotificationCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Notification $notification) {}

    /**
     * Private channel scoped to the recipient user — no one else receives it.
     */
    public function broadcastOn(): Channel
    {
        return new Channel('notifications.' . $this->notification->user_id);
    }

    public function broadcastWith(): array
    {
        return [
            'id'         => $this->notification->id,
            'message'    => $this->notification->message,
            'lu'         => false,
            'created_at' => $this->notification->created_at,
        ];
    }

    public function broadcastAs(): string
    {
        return 'NotificationCreated';
    }
}