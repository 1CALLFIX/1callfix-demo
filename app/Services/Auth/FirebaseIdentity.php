<?php

namespace App\Services\Auth;

/**
 * The normalised subset of a verified Firebase ID token's claims that the
 * auth flows actually use. Constructed only by an
 * App\Contracts\FirebaseTokenVerifier implementation, i.e. only after the
 * signature and standard claims have already been checked — holding an
 * instance of this means "Firebase vouched for these values".
 */
final readonly class FirebaseIdentity
{
    public function __construct(
        /** Firebase user id — the token `sub` / `user_id` claim. Always present. */
        public string $uid,
        /** E.164 phone number from a phone-auth token, e.g. "+919876543210". Null for a Google-only token. */
        public ?string $phoneNumber,
        public ?string $email,
        public bool $emailVerified,
        public ?string $name,
        public ?string $picture,
        /** `firebase.sign_in_provider`: "phone", "google.com", "password", ... */
        public ?string $signInProvider,
    ) {}

    public function isPhoneProvider(): bool
    {
        return $this->signInProvider === 'phone' || $this->phoneNumber !== null;
    }

    public function isGoogleProvider(): bool
    {
        return $this->signInProvider === 'google.com';
    }
}
