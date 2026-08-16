<?php

namespace App\Notifications\Adapters;

use App\Contracts\PushAdapter;
use App\Exceptions\InvalidPushTokenException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Real Firebase Cloud Messaging push delivery (BD-8) -- the one piece of
 * the old Glover Firebase setup that IS a clean, safe reuse for this
 * architecture (see KNOWN_RISKS_AND_DECISIONS.md item 8 and
 * PHASE_21_RELEASE_CANDIDATE_BACKLOG.md BD-8 for the full reasoning on why
 * Firebase AUTH/Phone-Auth is deliberately NOT used here -- this app's
 * OtpService is the real, already-complete, server-side-generated/
 * server-side-verified login OTP engine, and Firebase Phone Auth is a
 * structurally incompatible client-driven flow that would require
 * replacing it, not extending it. Firebase Cloud Messaging has no such
 * conflict -- it is purely push delivery, additive to the existing
 * PushAdapter contract).
 *
 * Talks to FCM's HTTP v1 API directly (no kreait/firebase-php dependency
 * added -- this repo's own convention favours the smallest safe change,
 * and v1 is a plain REST API once you have an OAuth2 access token). The
 * access token is obtained via the standard Google service-account
 * JWT-bearer flow (RFC 7523) using PHP's own openssl extension --
 * no external SDK needed for that either.
 *
 * NOT LIVE-VERIFIED -- no real Firebase service-account credentials exist
 * in this repository or environment. A real historical Firebase project
 * (onecallfix-6b538, per GLOVER_6AMMART_PARITY_AUDIT.md) is real
 * precedent that a Firebase project already exists for this business, but
 * its embedded credentials found in the 1.8.10 dump were already expired
 * and are not reused here (and would not be, even if valid -- credentials
 * are never carried across environments this way). A real service account
 * JSON for this app's own Firebase project must be generated and supplied
 * via FCM_CREDENTIALS_PATH/FCM_CREDENTIALS_JSON before this can be
 * trusted with real device pushes.
 */
class FirebaseFcmPushAdapter implements PushAdapter
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const CACHE_KEY = 'fcm_access_token';

    public function send(string $token, string $title, string $body): bool
    {
        $projectId = config('services.push.fcm.project_id');

        if (empty($projectId)) {
            Log::error('FirebaseFcmPushAdapter: missing FCM_PROJECT_ID; push not sent.');

            return false;
        }

        $accessToken = $this->getAccessToken();

        if ($accessToken === null) {
            // Already logged inside getAccessToken()/fetchAccessToken() --
            // a credentials/auth problem, not a per-token send failure.
            return false;
        }

        try {
            $response = Http::timeout(10)
                ->withToken($accessToken)
                ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                    'message' => [
                        'token' => $token,
                        'notification' => [
                            'title' => $title,
                            'body' => $body,
                        ],
                    ],
                ]);
        } catch (\Throwable $e) {
            Log::error('FirebaseFcmPushAdapter: request failed.', ['error' => $e->getMessage()]);

            return false;
        }

        if ($response->successful()) {
            return true;
        }

        // FCM v1's documented error shape for a dead token:
        // error.status === 'UNREGISTERED' (the token was valid once but the
        // app was uninstalled/token rotated) or === 'NOT_FOUND' (malformed/
        // never-valid token). Both mean "stop using this token", which
        // PushChannel reacts to by clearing the owning user's fcm_token --
        // see InvalidPushTokenException's own docblock.
        $errorStatus = $response->json('error.status');

        if (in_array($errorStatus, ['UNREGISTERED', 'NOT_FOUND'], true)) {
            throw new InvalidPushTokenException($token);
        }

        Log::error('FirebaseFcmPushAdapter: provider reported an error.', ['status' => $response->status(), 'error_status' => $errorStatus]);

        return false;
    }

    private function getAccessToken(): ?string
    {
        return Cache::remember(self::CACHE_KEY, 3300, function () {
            // 3300s (55min) cache, deliberately shorter than the token's
            // real ~3600s (1hr) lifetime -- avoids ever handing out a token
            // that expires mid-flight.
            return $this->fetchAccessToken();
        });
    }

    private function fetchAccessToken(): ?string
    {
        $credentials = $this->loadServiceAccountCredentials();

        if ($credentials === null) {
            Log::error('FirebaseFcmPushAdapter: no FCM service-account credentials configured (FCM_CREDENTIALS_PATH/FCM_CREDENTIALS_JSON); push not sent.');

            return null;
        }

        try {
            $assertion = $this->buildSignedJwt($credentials);
        } catch (\Throwable $e) {
            // Never include key material in this log line -- only the
            // exception's own message (openssl error strings do not
            // contain key bytes).
            Log::error('FirebaseFcmPushAdapter: failed to sign JWT assertion.', ['error' => $e->getMessage()]);

            return null;
        }

        try {
            $response = Http::asForm()->timeout(10)->post(self::TOKEN_URL, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);
        } catch (\Throwable $e) {
            Log::error('FirebaseFcmPushAdapter: token exchange request failed.', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::error('FirebaseFcmPushAdapter: token exchange rejected.', ['status' => $response->status()]);

            return null;
        }

        return $response->json('access_token');
    }

    /** @return array{client_email:string, private_key:string}|null */
    private function loadServiceAccountCredentials(): ?array
    {
        $json = config('services.push.fcm.credentials_json');

        if (empty($json)) {
            $path = config('services.push.fcm.credentials_path');
            if (! empty($path) && is_readable($path)) {
                $json = file_get_contents($path);
            }
        }

        if (empty($json)) {
            return null;
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded) || empty($decoded['client_email']) || empty($decoded['private_key'])) {
            return null;
        }

        return ['client_email' => $decoded['client_email'], 'private_key' => $decoded['private_key']];
    }

    /** @param array{client_email:string, private_key:string} $credentials */
    private function buildSignedJwt(array $credentials): string
    {
        $now = time();

        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $this->base64UrlEncode(json_encode([
            'iss' => $credentials['client_email'],
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        $signingInput = "{$header}.{$claims}";

        $signature = '';
        $signed = openssl_sign($signingInput, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256);

        if (! $signed) {
            throw new \RuntimeException('openssl_sign failed for FCM service-account JWT.');
        }

        return $signingInput.'.'.$this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
