<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Universal Chat (mission Phase 6) — `chat_messages` already existed
 * (booking_id/sender_id/receiver_id/message/attachment_url/read_at) but
 * was completely dormant: no service, no controller, no authorization, no
 * routes (confirmed by direct inspection before building this). A
 * "conversation" is always scoped to one real booking, between two of its
 * REAL participants — never an arbitrary pair of user IDs. This is the
 * one place that boundary is enforced, so every caller (API controller)
 * inherits it rather than re-checking it.
 *
 * Supports exactly the three combinations a booking can actually produce:
 * Customer<->Partner (always), Customer<->Worker (only once a worker is
 * assigned), Partner<->Worker (only once a worker is assigned) — derived
 * from the booking's own customer_id/provider.user_id/assignedWorker.user_id,
 * never a hardcoded pairing.
 */
class ChatService
{
    private const ALLOWED_MIME_TO_EXTENSION = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'application/pdf' => 'pdf',
    ];

    /** @return array<int> the real, distinct user IDs who may legitimately chat on this booking. */
    public function participantIds(Booking $booking): array
    {
        $booking->loadMissing('provider', 'assignedWorker');

        return array_values(array_unique(array_filter([
            $booking->customer_id,
            $booking->provider?->user_id,
            $booking->assignedWorker?->user_id,
        ])));
    }

    public function isParticipant(Booking $booking, int $userId): bool
    {
        return in_array($userId, $this->participantIds($booking), true);
    }

    /**
     * @throws \RuntimeException if either party isn't a genuine participant
     *         on this exact booking — the core IDOR guard: a sender can't
     *         message a receiver_id that just happens to exist elsewhere in
     *         the system, only someone actually on THIS booking.
     */
    public function send(Booking $booking, User $sender, int $receiverId, ?string $message, ?UploadedFile $attachment = null): ChatMessage
    {
        if (! $this->isParticipant($booking, $sender->id)) {
            throw new \RuntimeException('You are not a participant on this booking.');
        }

        if ($receiverId === $sender->id) {
            throw new \RuntimeException('You cannot message yourself.');
        }

        if (! $this->isParticipant($booking, $receiverId)) {
            throw new \RuntimeException('That recipient is not a participant on this booking.');
        }

        if (! $message && ! $attachment) {
            throw new \RuntimeException('A message or attachment is required.');
        }

        $attachmentPath = $attachment ? $this->storeAttachment($booking, $attachment) : null;

        return ChatMessage::create([
            'booking_id' => $booking->id,
            'sender_id' => $sender->id,
            'receiver_id' => $receiverId,
            'message' => $message,
            'attachment_url' => $attachmentPath,
        ]);
    }

    private function storeAttachment(Booking $booking, UploadedFile $file): string
    {
        if (! $file->isValid() || ! array_key_exists($file->getMimeType(), self::ALLOWED_MIME_TO_EXTENSION)) {
            throw new \RuntimeException('Unsupported attachment type. Only JPG, PNG, and PDF are accepted.');
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, array_values(self::ALLOWED_MIME_TO_EXTENSION), true)) {
            throw new \RuntimeException('Unsupported attachment extension.');
        }

        if ($file->getSize() > 10 * 1024 * 1024) {
            throw new \RuntimeException('Attachment exceeds the maximum allowed size of 10MB.');
        }

        $path = "chat/{$booking->id}/".Str::uuid().'.'.self::ALLOWED_MIME_TO_EXTENSION[$file->getMimeType()];
        Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));

        return $path;
    }

    /**
     * The two-party thread within this booking, oldest first. Both
     * $viewer and $withUserId must be real participants — same guard as
     * send(), so a viewer can't page through a booking's messages between
     * two OTHER people by supplying an arbitrary withUserId either.
     */
    public function conversation(Booking $booking, User $viewer, int $withUserId): Collection
    {
        if (! $this->isParticipant($booking, $viewer->id) || ! $this->isParticipant($booking, $withUserId)) {
            throw new \RuntimeException('This conversation is not accessible to you.');
        }

        return ChatMessage::where('booking_id', $booking->id)
            ->where(function ($q) use ($viewer, $withUserId) {
                $q->where(fn ($q2) => $q2->where('sender_id', $viewer->id)->where('receiver_id', $withUserId))
                    ->orWhere(fn ($q2) => $q2->where('sender_id', $withUserId)->where('receiver_id', $viewer->id));
            })
            ->orderBy('created_at')
            ->get();
    }

    /** Marks every unread message TO $viewer FROM $fromUserId on this booking as read — called whenever the viewer opens/polls the thread. */
    public function markRead(Booking $booking, User $viewer, int $fromUserId): int
    {
        return ChatMessage::where('booking_id', $booking->id)
            ->where('sender_id', $fromUserId)
            ->where('receiver_id', $viewer->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * Phase 21 item TECH-4 (Option A only — read-only admin viewer).
     * Deliberately NOT built on top of isParticipant()/conversation() —
     * those methods' whole point is rejecting anyone who isn't a genuine
     * party to the booking, which an admin/support viewer never is. This
     * returns every message on the booking, full stop, and carries NO
     * authorization check of its own: the caller (App\Livewire\Chat\Manage)
     * is responsible for establishing that the acting admin is actually
     * authorized to see THIS booking (chat.view + AuthorizationService::
     * scopeQuery() row-level scope) before ever calling this — matching
     * the same "screen owns its own scope, service just returns data"
     * boundary every other admin screen in this codebase already uses.
     * Read-only: never marks anything read, never mutates a message —
     * that would misrepresent the real participants' own read state to
     * each other on behalf of someone who never actually read it.
     */
    public function adminConversation(Booking $booking): Collection
    {
        return ChatMessage::where('booking_id', $booking->id)
            ->with(['sender', 'receiver'])
            ->orderBy('created_at')
            ->get();
    }
}
