<?php

namespace App\Contracts;

/**
 * Daily Digest session — no WhatsApp integration exists anywhere in this
 * codebase yet (confirmed by audit: no Meta Cloud API/Twilio WhatsApp/etc.
 * credentials, SDK, or prior adapter — unlike SmsAdapter, this is genuinely
 * new, not a rename of existing work). Same shape as SmsAdapter
 * deliberately: swap the bound implementation (see
 * AppServiceProvider::register()) for a real provider later — nothing
 * above this interface (DailyDigestDispatchService) needs to change.
 */
interface WhatsAppAdapter
{
    public function send(string $to, string $message): bool;
}
