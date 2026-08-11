<?php

namespace App\Notifications\Adapters;

use App\Contracts\PushAdapter;
use Illuminate\Support\Facades\Log;

/**
 * Default binding — no FCM/APNs credentials exist anywhere in this
 * codebase. Writes to the log instead of a real push service; see
 * LogSmsAdapter's docblock for the same reasoning.
 */
class LogPushAdapter implements PushAdapter
{
    public function send(string $token, string $title, string $body): bool
    {
        Log::info("[PUSH -> {$token}] {$title}: {$body}");

        return true;
    }
}
