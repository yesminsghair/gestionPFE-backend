<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message) {}

    /**
     * Public channel — no authentication required.
     * Matches: window.Echo.channel('conversation.{id}') in Messagerie.vue
     */
    public function broadcastOn(): Channel
    {
        return new Channel('conversation.' . $this->message->conversation_id);
    }

    /**
     * FIX: Added conversation_id so the Vue component can route the
     * incoming message to the correct conversation in onIncomingMessage().
     */
    public function broadcastWith(): array
    {
        return [
            'id'              => $this->message->id,
            'contenu'         => $this->message->contenu,
            'expediteur_id'   => $this->message->expediteur_id,
            'conversation_id' => $this->message->conversation_id, // ← ADDED
            'lu'              => false,
            'created_at'      => $this->message->created_at?->toIso8601String(),
        ];
    }

    /**
     * Event name used by the client: .listen('.MessageSent', ...)
     * The leading dot in the Vue listener skips Laravel's namespace prefix.
     */
    public function broadcastAs(): string
    {
        return 'MessageSent';
    }
}