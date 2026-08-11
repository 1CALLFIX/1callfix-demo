<?php

namespace App\Notifications\Adapters;

use App\Contracts\SmsAdapter;
use Illuminate\Support\Facades\Log;

/**
 * Default binding — no real SMS gateway is configured anywhere in this
 * codebase (confirmed by audit: no Twilio/MSG91/etc. credentials or SDK).
 * Writes to the log instead of a real carrier so the internal event ->
 * channel -> adapter flow is fully real and testable without sending an
 * actual text message or requiring credentials that don't exist yet.
 */
class LogSmsAdapter implements SmsAdapter
{
    public function send(string $to, string $message): bool
    {
        Log::info("[SMS -> {$to}] {$message}");

        return true;
    }
}
