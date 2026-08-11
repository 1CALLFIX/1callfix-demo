<?php

namespace App\Contracts;

/**
 * Swap the bound implementation (see AppServiceProvider::register()) for a
 * real provider (Twilio, MSG91, etc.) when one is chosen — nothing above
 * this interface (SmsChannel, the Notification classes that route through
 * it) needs to change.
 */
interface SmsAdapter
{
    public function send(string $to, string $message): bool;
}
