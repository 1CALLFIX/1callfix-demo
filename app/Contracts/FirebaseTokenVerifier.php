<?php

namespace App\Contracts;

use App\Exceptions\FirebaseAuthException;
use App\Services\Auth\FirebaseIdentity;

/**
 * Verifies a Firebase Authentication ID token (a signed RS256 JWT the
 * browser obtained from the Firebase JS SDK after a phone-OTP or Google
 * sign-in) and returns the identity it asserts.
 *
 * The auth rebuild made Firebase the proof-of-ownership step for phone
 * numbers and the mechanism for Google sign-in. The browser only ever
 * sends this token — never a "verified" boolean — and every server entry
 * point re-verifies it through this contract before trusting a single
 * claim inside it.
 *
 * Swap the bound implementation (AppServiceProvider::register()) for a
 * fake in tests; nothing above this interface changes.
 */
interface FirebaseTokenVerifier
{
    /**
     * @throws FirebaseAuthException when the token is missing, malformed,
     *         expired, signed by the wrong key, or issued for a different
     *         Firebase project.
     */
    public function verify(string $idToken): FirebaseIdentity;
}
