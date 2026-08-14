<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * In-app notification surface (mission Phase 8 -- "in_app" is a real,
 * already-configured channel in ChannelResolver::mapChannels(), mapped to
 * Laravel's own 'database' channel, and User already uses Notifiable — but
 * nothing anywhere ever let a Customer/Partner/Worker actually read what
 * was written there. This is the missing read side, nothing else changes:
 * every existing ->notify() call across the codebase already populates
 * this the moment 'in_app' is in its channel list.
 */
class InAppNotificationController extends Controller
{
    /** GET /api/notifications */
    public function index(Request $request)
    {
        $notifications = $request->user()->notifications()->paginate(20);

        return response()->json($notifications);
    }

    /** GET /api/notifications/unread-count */
    public function unreadCount(Request $request)
    {
        return response()->json(['count' => $request->user()->unreadNotifications()->count()]);
    }

    /** POST /api/notifications/{id}/read */
    public function markRead(Request $request, string $id)
    {
        $notification = $request->user()->notifications()->where('id', $id)->first();

        if (! $notification) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $notification->markAsRead();

        return response()->json(['message' => 'Marked as read.']);
    }

    /** POST /api/notifications/read-all */
    public function markAllRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['message' => 'All notifications marked as read.']);
    }
}
