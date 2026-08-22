<?php

namespace App\Notifications\Adapters;

use App\Contracts\WhatsAppAdapter;
use Illuminate\Support\Facades\Log;

/**
 * Default binding — no real WhatsApp gateway is configured anywhere in this
 * codebase. Writes to the log instead of a real API so the Daily Digest's
 * WhatsApp summary path is fully real and testable without sending an
 * actual message or requiring credentials that don't exist yet. Same
 * "safe default to log when unconfigured" behaviour as LogSmsAdapter.
 */
class LogWhatsAppAdapter implements WhatsAppAdapter
{
    public function send(string $to, string $message): bool
    {
        Log::info("[WhatsApp -> {$to}] {$message}");

        return true;
    }
}
