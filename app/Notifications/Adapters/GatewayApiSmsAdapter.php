<?php

namespace App\Notifications\Adapters;

use App\Contracts\SmsAdapter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Real GatewayAPI (gatewayapi.com) SMS delivery (BD-8). One of two
 * symmetric, equally-ready SMS adapters this pass built -- see
 * Msg91SmsAdapter's sibling docblock for why neither is auto-selected.
 *
 * Endpoint/payload shape mirrors the real, working Glover reference
 * implementation almost exactly (app/Services/OTPService.php's
 * "gatewayapi" branch in the Glover 1.8.5 source tree) -- HTTP Basic
 * auth with the API token as username, JSON body with sender/message/
 * recipients. Higher confidence than Msg91SmsAdapter specifically
 * because this shape is copied from a real prior implementation, not
 * built from documentation alone -- but still NOT LIVE-VERIFIED: no real
 * GatewayAPI account/token exists in this repository or environment (see
 * KNOWN_RISKS_AND_DECISIONS.md item 8).
 */
class GatewayApiSmsAdapter implements SmsAdapter
{
    private const ENDPOINT = 'https://gatewayapi.com/rest/mtsms';

    public function send(string $to, string $message): bool
    {
        $token = config('services.sms.gatewayapi.token');
        $sender = config('services.sms.gatewayapi.sender');

        if (empty($token) || empty($sender)) {
            Log::error('GatewayApiSmsAdapter: missing GATEWAYAPI_TOKEN/GATEWAYAPI_SENDER; SMS not sent.', ['to' => $this->maskPhone($to)]);

            return false;
        }

        try {
            $response = Http::timeout(10)
                ->withBasicAuth($token, '')
                ->post(self::ENDPOINT, [
                    'sender' => $sender,
                    'message' => $message,
                    'recipients' => [
                        ['msisdn' => $this->normalizePhone($to)],
                    ],
                ]);
        } catch (\Throwable $e) {
            Log::error('GatewayApiSmsAdapter: request failed.', ['to' => $this->maskPhone($to), 'error' => $e->getMessage()]);

            return false;
        }

        if (! $response->successful()) {
            Log::error('GatewayApiSmsAdapter: non-2xx response.', ['to' => $this->maskPhone($to), 'status' => $response->status()]);

            return false;
        }

        return true;
    }

    /** GatewayAPI's msisdn field expects digits only, no leading '+'. */
    private function normalizePhone(string $phone): string
    {
        return ltrim($phone, '+');
    }

    private function maskPhone(string $phone): string
    {
        return strlen($phone) > 4 ? substr($phone, 0, -4).'****' : '****';
    }
}
