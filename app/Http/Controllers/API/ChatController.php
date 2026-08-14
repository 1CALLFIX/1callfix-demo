<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\ChatMessage;
use App\Services\ChatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Universal Chat API — Customer<->Partner, Customer<->Worker, Partner<->
 * Worker, always scoped to one real booking. Every method resolves the
 * booking and re-checks participation itself (never trusts a route param
 * alone) — ChatService::send()/conversation() throw on anything else, and
 * those throws surface as 403 here, not a leaked 500 or a silently
 * empty/successful response.
 */
class ChatController extends Controller
{
    /** GET /api/bookings/{bookingId}/chat/{withUserId} */
    public function index(Request $request, int $bookingId, int $withUserId, ChatService $chat)
    {
        $booking = Booking::find($bookingId);
        if (! $booking) {
            return response()->json(['message' => 'Booking not found.'], 404);
        }

        try {
            $messages = $chat->conversation($booking, $request->user(), $withUserId);
            $chat->markRead($booking, $request->user(), $withUserId);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json(['messages' => $messages]);
    }

    /** POST /api/bookings/{bookingId}/chat */
    public function store(Request $request, int $bookingId, ChatService $chat)
    {
        $booking = Booking::find($bookingId);
        if (! $booking) {
            return response()->json(['message' => 'Booking not found.'], 404);
        }

        $validated = $request->validate([
            'receiver_id' => ['required', 'integer'],
            'message' => ['nullable', 'string', 'max:2000'],
            'attachment' => ['nullable', 'file'],
        ]);

        try {
            $chatMessage = $chat->send(
                $booking,
                $request->user(),
                (int) $validated['receiver_id'],
                $validated['message'] ?? null,
                $request->file('attachment'),
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json($chatMessage, 201);
    }

    /** GET /api/chat/attachments/{messageId} — private, authorization-gated retrieval, never a public URL. */
    public function attachment(Request $request, int $messageId)
    {
        $message = ChatMessage::find($messageId);
        if (! $message || ! $message->attachment_url) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $userId = $request->user()->id;
        if ($userId !== $message->sender_id && $userId !== $message->receiver_id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if (! Storage::disk('local')->exists($message->attachment_url)) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return Storage::disk('local')->response($message->attachment_url, null, ['Content-Disposition' => 'inline']);
    }
}
