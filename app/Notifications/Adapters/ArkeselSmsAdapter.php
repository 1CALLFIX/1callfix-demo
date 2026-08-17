<?php

namespace App\Notifications\Adapters;

use App\Contracts\SmsAdapter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Real Arkesel (sms.arkesel.com) SMS delivery -- the SMS gateway
 * confirmed ACTUALLY CONFIGURED (not merely coded-as-an-option) in the
 * real Glover 1.8.5 reference deployment: its `.env` has real
 * `ARKESEL_AUTHKEY`/`ARKESEL_SENDER` values populated while every other
 * gateway branch in `app/Services/OTPService.php` (Twilio, MSG91,
 * GatewayAPI, Termii, Africa's Talking, Hubtel) has no credentials set at
 * all. This is the "known working" SMS provider the business asked to
 * reuse, distinct from `Msg91SmsAdapter`/`GatewayApiSmsAdapter` (BD-8's
 * earlier, equally-valid-but-unconfirmed candidates -- see
 * `KNOWN_RISKS_AND_DECISIONS.md` item 8 for the full history).
 *
 * Endpoint/query-param shape mirrors Glover's `OTPService.php` "arkesel"
 * branch exactly: a single GET request to
 * `https://sms.arkesel.com/sms/api?action=send-sms&api_key=...&to=...&from=...&sms=...`.
 * Built via `Http::get($url, $query)` (matching this app's sibling
 * adapters' testable convention) rather than raw curl -- functionally
 * identical request, just issued through Laravel's HTTP client so it is
 * fully fakeable in tests.
 *
 * NOT LIVE-VERIFIED from this repository -- the real Arkesel credentials
 * exist only in the Glover deployment's own server environment, never
 * copied into this repository or its `.env`/`.env.example` (see
 * `KNOWN_RISKS_AND_DECISIONS.md` item 8 and this adapter's own
 * `ARKESEL_API_KEY`/`ARKESEL_SENDER` entries in `.env.example`). A real
 * integration check against a real Arkesel account must happen in a real
 * environment before this is trusted with real customer traffic.
 */
class ArkeselSmsAdapter implements SmsAdapter
{
    private const ENDPOINT = 'https://sms.arkesel.com/sms/api';

    public function send(string $to, string $message): bool
    {
        $apiKey = config('services.sms.arkesel.api_key');
        $sender = config('services.sms.arkesel.sender');

        if (empty($apiKey) || empty($sender)) {
            Log::error('ArkeselSmsAdapter: missing ARKESEL_API_KEY/ARKESEL_SENDER; SMS not sent.', ['to' => $this->maskPhone($to)]);

            return false;
        }

        try {
            $response = Http::timeout(10)->get(self::ENDPOINT, [
                'action' => 'send-sms',
                'api_key' => $apiKey,
                'to' => $this->normalizePhone($to),
                'from' => $sender,
                'sms' => $message,
            ]);
        } catch (\Throwable $e) {
            // Never leak the message body (may contain a real OTP code)
            // into the exception/log line -- matches every sibling adapter.
            Log::error('ArkeselSmsAdapter: request failed.', ['to' => $this->maskPhone($to), 'error' => $e->getMessage()]);

            return false;
        }

        if (! $response->successful()) {
            Log::error('ArkeselSmsAdapter: non-2xx response.', ['to' => $this->maskPhone($to), 'status' => $response->status()]);

            return false;
        }

        return true;
    }

    /** Arkesel's `to` param expects digits only (with country code), no leading '+' or spaces -- matches Glover's own normalization. */
    private function normalizePhone(string $phone): string
    {
        return str_replace([' ', '+'], '', $phone);
    }

    private function maskPhone(string $phone): string
    {
        return strlen($phone) > 4 ? substr($phone, 0, -4).'****' : '****';
    }
}
