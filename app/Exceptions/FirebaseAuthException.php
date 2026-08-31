<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised by App\Contracts\FirebaseTokenVerifier when an ID token cannot be
 * trusted — bad signature, expired, wrong audience/issuer, malformed, or
 * absent. Callers translate this into a generic 401/422 for the client;
 * the specific reason is for logs, never the response body.
 */
class FirebaseAuthException extends RuntimeException
{
    public static function invalid(string $detail): self
    {
        return new self("Firebase ID token rejected: {$detail}");
    }
}
