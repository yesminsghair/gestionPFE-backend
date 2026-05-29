<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Log;
use App\Events\MessageSent;
use App\Events\NotificationCreated;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Notification;
use App\Models\Utilisateur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MessageController extends Controller
{
    // ──────────────────────────────────────────────────────────────
    // GET /api/conversations
    // Fixed: eager-loads user1 & user2 to eliminate N+1 queries.
    // ──────────────────────────────────────────────────────────────
    public function conversations(): JsonResponse
    {
        $userId = Auth::id();

        $convs = Conversation::with([
                'user1',
                'user2',
                // Only the latest message per conversation
                'messages' => fn($q) => $q->latest()->limit(1),
            ])
            ->where('user1_id', $userId)
            ->orWhere('user2_id', $userId)
            ->orderBy('updated_at', 'desc')
            ->get()
            ->map(function ($conv) use ($userId) {
                // No extra DB query — already eager-loaded
                $interlocuteur = $conv->user1_id === $userId ? $conv->user2 : $conv->user1;
                $lastMsg       = $conv->messages->first();

                // Unread count: messages from the other person that are unread
                $nonLu = Message::where('conversation_id', $conv->id)
                    ->where('expediteur_id', '!=', $userId)
                    ->where('lu', false)
                    ->count();

                return [
                    'id'                 => $conv->id,
                    'interlocuteur_id'   => $interlocuteur?->id,
                    'interlocuteur_nom'  => $interlocuteur
                        ? trim($interlocuteur->prenom . ' ' . $interlocuteur->nom)
                        : '—',
                    'interlocuteur_role' => $interlocuteur?->role,
                    'dernier_message'    => $lastMsg?->contenu,
                    'non_lu'             => $nonLu,
                    'updated_at'         => $conv->updated_at,
                ];
            });

        return response()->json($convs);
    }

    // ──────────────────────────────────────────────────────────────
    // POST /api/conversations — create or return existing
    // ──────────────────────────────────────────────────────────────
    public function createConversation(Request $request): JsonResponse
    {
        $request->validate(['destinataire_id' => 'required|exists:utilisateurs,id']);

        $userId = Auth::id();
        $destId = (int) $request->destinataire_id;

        if ($userId === $destId) {
            return response()->json(['message' => 'Impossible de vous écrire à vous-même.'], 422);
        }

        $existing = Conversation::where(function ($q) use ($userId, $destId) {
            $q->where('user1_id', $userId)->where('user2_id', $destId);
        })->orWhere(function ($q) use ($userId, $destId) {
            $q->where('user1_id', $destId)->where('user2_id', $userId);
        })->first();

        if ($existing) {
            return response()->json($this->formatConv($existing, $destId));
        }

        $conv = Conversation::create(['user1_id' => $userId, 'user2_id' => $destId]);

        return response()->json($this->formatConv($conv, $destId), 201);
    }

    // ──────────────────────────────────────────────────────────────
    // GET /api/conversations/{id}/messages?before_id=X&limit=50
    // Added: cursor-based pagination — no more loading full history.
    // ──────────────────────────────────────────────────────────────
    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeConversation($conversation);

        $limit = min((int) ($request->limit ?? 50), 100);

        $query = Message::where('conversation_id', $conversation->id)
            ->orderBy('created_at', 'desc')
            ->limit($limit);

        // Cursor: load messages older than the given ID (for "load more" / infinite scroll)
        if ($request->filled('before_id')) {
            $query->where('id', '<', (int) $request->before_id);
        }

        $msgs = $query->get()
            ->reverse()
            ->values()
            ->map(fn($m) => [
                'id'            => $m->id,
                'contenu'       => $m->contenu,
                'expediteur_id' => $m->expediteur_id,
                'lu'            => (bool) $m->lu,
                'created_at'    => $m->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'data'        => $msgs,
            'has_more'    => $msgs->count() === $limit,
            'oldest_id'   => $msgs->first()['id'] ?? null,
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    // POST /api/conversations/{id}/messages
    // Added: broadcasts MessageSent & NotificationCreated events.
    // ──────────────────────────────────────────────────────────────
   public function sendMessage(Request $request, Conversation $conversation): JsonResponse
{
    Log::info('1. sendMessage STARTED', ['conv_id' => $conversation->id]);
    
    $this->authorizeConversation($conversation);
    $request->validate(['contenu' => 'required|string|max:2000']);

    $expediteur = Auth::user();
    Log::info('2. User authenticated', ['user_id' => $expediteur->id]);

    $msg = Message::create([
        'conversation_id' => $conversation->id,
        'expediteur_id'   => $expediteur->id,
        'contenu'         => $request->contenu,
        'lu'              => false,
    ]);
    Log::info('3. Message created', ['message_id' => $msg->id]);

    $conversation->touch();

    $destinataireId = $conversation->user1_id === $expediteur->id
        ? $conversation->user2_id
        : $conversation->user1_id;

    $expediteurNom = trim($expediteur->prenom . ' ' . $expediteur->nom);

    $notif = Notification::create([
        'user_id'    => $destinataireId,
        'message'    => "Vous avez un nouveau message de {$expediteurNom}.",
        'lu'         => false,
        'created_at' => now(),
    ]);
    Log::info('4. Notification created', ['notif_id' => $notif->id]);

    Log::info('5. About to broadcast MessageSent');
    broadcast(new MessageSent($msg))->toOthers();
    broadcast(new NotificationCreated($notif));
    Log::info('6. Broadcast completed');

    return response()->json([
        'id'            => $msg->id,
        'contenu'       => $msg->contenu,
        'expediteur_id' => $msg->expediteur_id,
        'lu'            => false,
        'created_at'    => $msg->created_at?->toIso8601String(),
    ], 201);
}

    // ──────────────────────────────────────────────────────────────
    // PUT /api/conversations/{id}/lire
    // ──────────────────────────────────────────────────────────────
    public function markConversationRead(Conversation $conversation): JsonResponse
    {
        $this->authorizeConversation($conversation);

        $updated = Message::where('conversation_id', $conversation->id)
            ->where('expediteur_id', '!=', Auth::id())
            ->where('lu', false)          // Only touch truly unread rows
            ->update(['lu' => true]);

        return response()->json(['marked' => $updated]);
    }

    // ──────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────
    private function authorizeConversation(Conversation $conv): void
    {
        $userId = Auth::id();
        if ($conv->user1_id !== $userId && $conv->user2_id !== $userId) {
            abort(403, 'Non autorisé.');
        }
    }

    private function formatConv(Conversation $conv, int $interlocuteurId): array
    {
        $interlocuteur = Utilisateur::find($interlocuteurId);
        return [
            'id'                 => $conv->id,
            'interlocuteur_id'   => $interlocuteurId,
            'interlocuteur_nom'  => $interlocuteur
                ? trim($interlocuteur->prenom . ' ' . $interlocuteur->nom)
                : '—',
            'interlocuteur_role' => $interlocuteur?->role,
            'dernier_message'    => null,
            'non_lu'             => 0,
            'updated_at'         => $conv->updated_at,
        ];
    }
}