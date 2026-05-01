<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Notification;
use App\Models\Utilisateur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MessageController extends Controller
{
    // GET /api/conversations
    public function conversations(): JsonResponse
    {
        $userId = Auth::id();

        $convs = Conversation::with(['messages' => fn($q) => $q->latest()->limit(1)])
            ->where('user1_id', $userId)
            ->orWhere('user2_id', $userId)
            ->orderBy('updated_at', 'desc')
            ->get()
            ->map(function ($conv) use ($userId) {
                $interlocuteurId = $conv->user1_id === $userId ? $conv->user2_id : $conv->user1_id;
                $interlocuteur   = Utilisateur::find($interlocuteurId);
                $lastMsg         = $conv->messages->first();
                $nonLu           = Message::where('conversation_id', $conv->id)
                    ->where('expediteur_id', '!=', $userId)
                    ->where('lu', false)
                    ->count();

                return [
                    'id'                   => $conv->id,
                    'interlocuteur_id'     => $interlocuteurId,
                    'interlocuteur_nom'    => $interlocuteur
                        ? trim($interlocuteur->prenom . ' ' . $interlocuteur->nom)
                        : '—',
                    'interlocuteur_role'   => $interlocuteur?->role,
                    'dernier_message'      => $lastMsg?->contenu,
                    'non_lu'               => $nonLu,
                    'updated_at'           => $conv->updated_at,
                ];
            });

        return response()->json($convs);
    }

    // POST /api/conversations — create or return existing
    public function createConversation(Request $request): JsonResponse
    {
        $request->validate(['destinataire_id' => 'required|exists:utilisateurs,id']);

        $userId = Auth::id();
        $destId = $request->destinataire_id;

        if ($userId === $destId) {
            return response()->json(['message' => 'Impossible de vous écrire à vous-même.'], 422);
        }

        // Check if conversation already exists
        $existing = Conversation::where(function ($q) use ($userId, $destId) {
            $q->where('user1_id', $userId)->where('user2_id', $destId);
        })->orWhere(function ($q) use ($userId, $destId) {
            $q->where('user1_id', $destId)->where('user2_id', $userId);
        })->first();

        if ($existing) {
            $interlocuteur = Utilisateur::find($destId);
            return response()->json([
                'id'                 => $existing->id,
                'interlocuteur_id'   => $destId,
                'interlocuteur_nom'  => $interlocuteur ? trim($interlocuteur->prenom . ' ' . $interlocuteur->nom) : '—',
                'interlocuteur_role' => $interlocuteur?->role,
                'dernier_message'    => null,
                'non_lu'             => 0,
                'updated_at'         => $existing->updated_at,
            ]);
        }

        $conv = Conversation::create([
            'user1_id' => $userId,
            'user2_id' => $destId,
        ]);

        $interlocuteur = Utilisateur::find($destId);
        return response()->json([
            'id'                 => $conv->id,
            'interlocuteur_id'   => $destId,
            'interlocuteur_nom'  => $interlocuteur ? trim($interlocuteur->prenom . ' ' . $interlocuteur->nom) : '—',
            'interlocuteur_role' => $interlocuteur?->role,
            'dernier_message'    => null,
            'non_lu'             => 0,
            'updated_at'         => $conv->updated_at,
        ], 201);
    }

    // GET /api/conversations/{id}/messages
    public function messages(Conversation $conversation): JsonResponse
    {
        $this->authorizeConversation($conversation);

        $msgs = Message::where('conversation_id', $conversation->id)
            ->orderBy('created_at')
            ->get()
            ->map(fn($m) => [
                'id'            => $m->id,
                'contenu'       => $m->contenu,
                'expediteur_id' => $m->expediteur_id,
                'lu'            => (bool)$m->lu,
                'created_at'    => $m->created_at?->toIso8601String(),
            ]);

        return response()->json($msgs);
    }

    // POST /api/conversations/{id}/messages
    public function sendMessage(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeConversation($conversation);
        $request->validate(['contenu' => 'required|string|max:2000']);

        $expediteur = Auth::user();

        $msg = Message::create([
            'conversation_id' => $conversation->id,
            'expediteur_id'   => $expediteur->id,
            'contenu'         => $request->contenu,
            'lu'              => false,
        ]);

        $conversation->touch();

        // Notify the recipient
        $destinataireId = $conversation->user1_id === $expediteur->id
            ? $conversation->user2_id
            : $conversation->user1_id;

        $expediteurNom = trim($expediteur->prenom . ' ' . $expediteur->nom);

        Notification::create([
            'user_id'    => $destinataireId,
            'message'    => "Vous avez un nouveau message de {$expediteurNom}.",
            'lu'         => false,
            'created_at' => now(),
        ]);

        return response()->json([
            'id'            => $msg->id,
            'contenu'       => $msg->contenu,
            'expediteur_id' => $msg->expediteur_id,
            'lu'            => false,
            'created_at'    => $msg->created_at?->toIso8601String(),
        ], 201);
    }

    // PUT /api/conversations/{id}/lire — mark all messages in conv as read
    public function markConversationRead(Conversation $conversation): JsonResponse
    {
        $this->authorizeConversation($conversation);

        Message::where('conversation_id', $conversation->id)
            ->where('expediteur_id', '!=', Auth::id())
            ->update(['lu' => true]);

        return response()->json(['message' => 'Messages marqués comme lus.']);
    }

    private function authorizeConversation(Conversation $conv): void
    {
        $userId = Auth::id();
        if ($conv->user1_id !== $userId && $conv->user2_id !== $userId) {
            abort(403, 'Non autorisé.');
        }
    }
}