<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 21 item TECH-4 (Option A only — read-only admin viewer). The ONLY
 * admin-facing way a chat attachment is ever retrieved — streamed from the
 * PRIVATE disk after an independent authorization check, mirroring
 * KycDocumentController's exact convention (never a raw public URL, 404
 * not 403 on any authorization failure so a guessed message id that
 * exists but isn't yours doesn't even confirm its own existence).
 *
 * Deliberately does NOT reuse ChatController::attachment() or weaken its
 * existing sender_id/receiver_id participant check in any way — that
 * route remains exactly as strict as before for real chat participants.
 * This is a second, separate, admin-only path with its own independent
 * chat.view + row-level scope check, resolved through the message's own
 * booking (never trusting a route param alone) — a guessed message id
 * belonging to another franchise's booking is rejected here even if the
 * requester genuinely holds chat.view somewhere else.
 */
class ChatAttachmentController extends Controller
{
    public function show(int $messageId)
    {
        $message = ChatMessage::with('booking')->find($messageId);
        abort_if(! $message || ! $message->attachment_url || ! $message->booking, 404);

        $booking = $message->booking;
        $allowed = auth()->user()->hasPermission('chat.view', array_filter([
            'zone_id' => $booking->zone_id,
            'franchise_id' => $booking->franchise_id,
        ]));

        abort_if(! $allowed, 404);
        abort_if(! Storage::disk('local')->exists($message->attachment_url), 404);

        return Storage::disk('local')->response($message->attachment_url, null, ['Content-Disposition' => 'inline']);
    }
}
