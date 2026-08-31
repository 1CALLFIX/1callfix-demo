<?php

namespace App\Services\Auth;

use App\Contracts\FirebaseTokenVerifier;
use App\Exceptions\FirebaseAuthException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Verifies a Firebase ID token against Google's published signing
 * certificates, entirely with firebase/php-jwt + openssl (no kreait, no
 * ext-sodium — see docs/auth-otp-consumer-audit.md and the plan for why).
 *
 * A Firebase ID token is an RS256 JWT. This class:
 *   1. fetches Google's securetoken x509 certs (cached, honouring the
 *      response's Cache-Control max-age),
 *   2. lets JWT::decode() pick the cert by the token's `kid` header and
 *      verify the signature + `exp` / `iat` / `nbf`,
 *   3. asserts the Firebase-specific claims: `aud` == this project,
 *      `iss` == https://securetoken.google.com/<project>, `sub` non-empty.
 *
 * Anything that fails becomes a FirebaseAuthException with a log-only
 * reason; callers surface a generic auth failure to the client.
 */
class GoogleFirebaseTokenVerifier implements FirebaseTokenVerifier
{
    private const CERT_URL = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';

    private const CERT_CACHE_KEY = 'firebase:securetoken:x509';

    private const CLOCK_LEEWAY_SECONDS = 60;

    public function __construct(private readonly ?string $projectId) {}

    public function verify(string $idToken): FirebaseIdentity
    {
        $projectId = trim((string) $this->projectId);

        if ($projectId === '') {
            // A configuration problem, not a bad token — make it obvious.
            throw new FirebaseAuthException('services.firebase.project_id is not configured.');
        }

        if (trim($idToken) === '') {
            throw FirebaseAuthException::invalid('empty token');
        }

        $keys = $this->signingKeys();

        $previousLeeway = JWT::$leeway;
        JWT::$leeway = self::CLOCK_LEEWAY_SECONDS;

        try {
            $claims = (array) JWT::decode($idToken, $keys);
        } catch (Throwable $e) {
            throw FirebaseAuthException::invalid($e->getMessage());
        } finally {
            JWT::$leeway = $previousLeeway;
        }

        $this->assertClaim($claims, 'aud', $projectId);
        $this->assertClaim($claims, 'iss', "https://securetoken.google.com/{$projectId}");

        $sub = isset($claims['sub']) ? (string) $claims['sub'] : '';
        if ($sub === '') {
            throw FirebaseAuthException::invalid('missing subject');
        }

        $firebase = (array) ($claims['firebase'] ?? []);

        return new FirebaseIdentity(
            uid: $sub,
            phoneNumber: $this->stringOrNull($claims['phone_number'] ?? null),
            email: $this->stringOrNull($claims['email'] ?? null),
            emailVerified: (bool) ($claims['email_verified'] ?? false),
            name: $this->stringOrNull($claims['name'] ?? null),
            picture: $this->stringOrNull($claims['picture'] ?? null),
            signInProvider: $this->stringOrNull($firebase['sign_in_provider'] ?? null),
        );
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function assertClaim(array $claims, string $key, string $expected): void
    {
        if (($claims[$key] ?? null) !== $expected) {
            throw FirebaseAuthException::invalid("{$key} mismatch");
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<string, Key>
     */
    private function signingKeys(): array
    {
        $certs = Cache::remember(self::CERT_CACHE_KEY, now()->addHour(), function () {
            $response = Http::acceptJson()->get(self::CERT_URL);

            if (! $response->successful()) {
                throw FirebaseAuthException::invalid('could not fetch Google signing certificates');
            }

            return $response->json();
        });

        if (! is_array($certs) || $certs === []) {
            Cache::forget(self::CERT_CACHE_KEY);
            throw FirebaseAuthException::invalid('empty Google signing certificate set');
        }

        $keys = [];
        foreach ($certs as $kid => $pem) {
            $keys[(string) $kid] = new Key((string) $pem, 'RS256');
        }

        return $keys;
    }
}
