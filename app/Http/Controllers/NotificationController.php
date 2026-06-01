<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    public function index(): JsonResponse
    {
        $notifications = Notification::where('user_id', Auth::id())
            ->orderByDesc('id')
            ->get();

        return response()->json($notifications);
    }

    public function markAsRead(Notification $notification): JsonResponse
    {
        abort_if($notification->user_id !== Auth::id(), 403);
        $notification->update(['lu' => true]);
        return response()->json($notification);
    }

    public function markAllAsRead(): JsonResponse
    {
        Notification::where('user_id', Auth::id())->update(['lu' => true]);
        return response()->json(['message' => 'Toutes les notifications ont été marquées comme lues']);
    }

    public function unreadCount(): JsonResponse
    {
        $count = Notification::where('user_id', Auth::id())
            ->where('lu', false)
            ->count();

        return response()->json(['count' => $count]);
    }

    public function destroy(Notification $notification): JsonResponse
    {
        $notification->delete();
        return response()->json(['message' => 'Notification supprimée']);
    }
}