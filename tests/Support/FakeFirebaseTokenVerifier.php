<?php

namespace Tests\Support;

use App\Contracts\FirebaseTokenVerifier;
use App\Exceptions\FirebaseAuthException;
use App\Services\Auth\FirebaseIdentity;
use Illuminate\Support\Str;

/**
 * Test-only stand-in for App\Contracts\FirebaseTokenVerifier — bound over
 * the real verifier in tests exactly the way CapturingSmsAdapter is bound
 * over SmsAdapter. No network, no real JWT: a test mints an opaque token
 * string paired with the identity it should resolve to, so the auth flows
 * under test run their real branching on real FirebaseIdentity values.
 *
 * Anything not registered (or explicitly rejected) throws
 * FirebaseAuthException, so "bad token" paths are exercised for real too.
 */
class FakeFirebaseTokenVerifier implements FirebaseTokenVerifier
{
    /** @var array<string, FirebaseIdentity> */
    private array $identities = [];

    /** @var array<string, string> token => rejection reason */
    private array $rejects = [];

    /** Register a phone-auth identity and return the opaque token that resolves to it. */
    public function issuePhoneToken(string $phoneE164, ?string $uid = null): string
    {
        $token = 'fake-phone-'.Str::random(24);
        $this->identities[$token] = new FirebaseIdentity(
            uid: $uid ?? 'uid-'.Str::of($phoneE164)->replaceMatches('/\D/', ''),
            phoneNumber: $phoneE164,
            email: null,
            emailVerified: false,
            name: null,
            picture: null,
            signInProvider: 'phone',
        );

        return $token;
    }

    /** Register a Google identity and return the opaque token that resolves to it. */
    public function issueGoogleToken(string $email, ?string $name = null, ?string $uid = null, bool $emailVerified = true, ?string $picture = null): string
    {
        $token = 'fake-google-'.Str::random(24);
        $this->identities[$token] = new FirebaseIdentity(
            uid: $uid ?? 'guid-'.Str::random(16),
            phoneNumber: null,
            email: $email,
            emailVerified: $emailVerified,
            name: $name,
            picture: $picture,
            signInProvider: 'google.com',
        );

        return $token;
    }

    /** Register an arbitrary identity for full control. */
    public function issue(FirebaseIdentity $identity): string
    {
        $token = 'fake-'.Str::random(28);
        $this->identities[$token] = $identity;

        return $token;
    }

    public function rejectToken(string $token, string $reason = 'test rejection'): void
    {
        $this->rejects[$token] = $reason;
    }

    public function verify(string $idToken): FirebaseIdentity
    {
        if (isset($this->rejects[$idToken])) {
            throw FirebaseAuthException::invalid($this->rejects[$idToken]);
        }

        return $this->identities[$idToken]
            ?? throw FirebaseAuthException::invalid('unknown token');
    }
}
