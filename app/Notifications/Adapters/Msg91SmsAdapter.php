<?php

namespace App\Notifications\Adapters;

use App\Contracts\SmsAdapter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Real MSG91 SMS delivery (BD-8). One of two symmetric, equally-ready SMS
 * adapters this pass built -- see GatewayApiSmsAdapter's sibling docblock.
 * Neither is auto-selected; config('services.sms.driver') (SMS_DRIVER env)
 * decides, defaulting to 'log' (LogSmsAdapter) until an operator sets it.
 *
 * Uses MSG91's documented "SendHTTP" plain-text SMS API
 * (https://api.msg91.com/api/sendhttp.php) rather than MSG91's newer
 * Flow/template API, deliberately: this app's OtpNotification/
 * BookingOtpNotification already compose the full message text
 * server-side (see OtpNotification::smsBody()) and this contract
 * (SmsAdapter::send(string $to, string $message)) sends an arbitrary
 * pre-composed string -- Flow requires a pre-registered template id this
 * repo does not have and cannot invent.
 *
 * NOT LIVE-VERIFIED -- no real MSG91 account/credentials exist in this
 * repository or environment (see KNOWN_RISKS_AND_DECISIONS.md item 8).
 * Built against MSG91's public API documentation only. A real integration
 * test against a real MSG91 sandbox/account must happen before this is
 * trusted with real customer traffic.
 *
 * Real-world regulatory note: Indian DLT/TRAI regulations require SMS
 * routes/templates to be pre-registered with the telecom regulator before
 * transactional/OTP SMS can legally be sent to Indian numbers on most
 * routes. Route 4 (transactional) still generally requires DLT
 * registration for the sender id/entity as of this writing. This is a
 * real production prerequisite outside this codebase's control -- not
 * fixed or worked around here.
 */
class Msg91SmsAdapter implements SmsAdapter
{
    public function send(string $to, string $message): bool
    {
        $authKey = config('services.sms.msg91.auth_key');
        $senderId = config('services.sms.msg91.sender_id');
        $route = config('services.sms.msg91.route', '4');

        if (empty($authKey) || empty($senderId)) {
            // Fails safely/loudly rather than silently pretending success --
            // this is a real misconfiguration (driver selected without
            // credentials), not a provider-side failure.
            Log::error('Msg91SmsAdapter: missing MSG91_AUTH_KEY/MSG91_SENDER_ID; SMS not sent.', ['to' => $this->maskPhone($to)]);

            return false;
        }

        try {
            $response = Http::timeout(10)->get('https://api.msg91.com/api/sendhttp.php', [
                'authkey' => $authKey,
                'mobiles' => $this->normalizePhone($to),
                'message' => $message,
                'sender' => $senderId,
                'route' => $route,
                'response' => 'json',
            ]);
        } catch (\Throwable $e) {
            // Network/timeout failure -- never leak the message body (which
            // may contain a real OTP code) into the exception/log line.
            Log::error('Msg91SmsAdapter: request failed.', ['to' => $this->maskPhone($to), 'error' => $e->getMessage()]);

            return false;
        }

        if (! $response->successful()) {
            Log::error('Msg91SmsAdapter: non-2xx response.', ['to' => $this->maskPhone($to), 'status' => $response->status()]);

            return false;
        }

        $body = $response->json();

        // MSG91's SendHTTP API returns either a bare request-id string or a
        // JSON object with a 'type' field of 'success'/'error' depending on
        // format/version -- treat any non-explicit-error response as sent,
        // matching the provider's own documented ambiguity here (never
        // invented, since this is not live-verified).
        if (is_array($body) && ($body['type'] ?? null) === 'error') {
            Log::error('Msg91SmsAdapter: provider reported an error.', ['to' => $this->maskPhone($to), 'message' => $body['message'] ?? null]);

            return false;
        }

        return true;
    }

    /** MSG91 expects a bare number with country code, no leading '+'. */
    private function normalizePhone(string $phone): string
    {
        return ltrim($phone, '+');
    }

    private function maskPhone(string $phone): string
    {
        return strlen($phone) > 4 ? substr($phone, 0, -4).'****' : '****';
    }
}
