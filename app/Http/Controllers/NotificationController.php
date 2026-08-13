<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    private const PER_PAGE = 30;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'notifications' => $user->notifications()->limit(self::PER_PAGE)->get(),
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    public function markRead(Request $request, DatabaseNotification $notification): JsonResponse
    {
        $this->authorizeOwnership($request, $notification);

        $notification->markAsRead();

        return response()->json(['message' => 'Notification marked as read']);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['message' => 'All notifications marked as read']);
    }

    private function authorizeOwnership(Request $request, DatabaseNotification $notification): void
    {
        $user = $request->user();

        abort_unless(
            $notification->notifiable_type === $user::class && (int) $notification->notifiable_id === $user->id,
            403,
        );
    }
}
