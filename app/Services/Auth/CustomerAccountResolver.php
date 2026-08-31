<?php

namespace App\Services\Auth;

use App\Exceptions\AccountAlreadyExistsException;
use App\Exceptions\FirebaseAuthException;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The ONE place a customer `users` row is looked up or provisioned, for
 * every auth surface (Livewire web session AND the REST API token flow).
 * Both call the same instance so a rule changed here is observed by both —
 * the guarantee tests/Feature/CustomerWeb/AuthPathEquivalenceTest.php
 * exists to defend.
 *
 * After the auth rebuild the proof-of-ownership step is either a verified
 * Firebase ID token (phone / Google — see FirebaseTokenVerifier) or a
 * verified email OTP (OtpService). This class is only ever reached once a
 * caller already holds that proof; it never verifies anything itself.
 *
 * Mobile stays the mandatory primary identifier: every row this class
 * creates has a real, Firebase-verified `phone`. Email is optional and
 * secondary. There is deliberately no method here that creates an account
 * without a phone.
 */
class CustomerAccountResolver
{
    // ───────────────────────────── Lookups (no writes) ─────────────────────

    public function findByLoginIdentifier(string $identifier): ?User
    {
        $identifier = trim($identifier);

        return self::isEmail($identifier)
            ? $this->findByEmail($identifier)
            : $this->findByPhone($identifier);
    }

    public function findByPhone(string $phone): ?User
    {
        $national = PhoneNumber::national($phone);
        if ($national === '') {
            return null;
        }

        return User::where('phone', $national)->first();
    }

    public function findByEmail(string $email): ?User
    {
        return User::whereRaw('LOWER(email) = ?', [Str::lower(trim($email))])->first();
    }

    public function findByFirebaseUid(string $uid): ?User
    {
        return $uid === '' ? null : User::where('firebase_uid', $uid)->first();
    }

    /**
     * The existing account a verified Firebase identity already maps to, if
     * any: by firebase_uid, then by verified phone number, then (Google
     * only) by email. Null means the caller must gather more (a phone, for
     * a Google-first signup) before an account can exist.
     */
    public function findForFirebaseIdentity(FirebaseIdentity $identity): ?User
    {
        if ($byUid = $this->findByFirebaseUid($identity->uid)) {
            return $byUid;
        }

        if ($identity->phoneNumber && $byPhone = $this->findByPhone($identity->phoneNumber)) {
            return $byPhone;
        }

        if ($identity->isGoogleProvider() && $identity->email && $byEmail = $this->findByEmail($identity->email)) {
            return $byEmail;
        }

        return null;
    }

    // ───────────────────────────── Provisioning ───────────────────────────

    /**
     * Finish a signup whose phone has just been Firebase-verified.
     *
     * - No existing row for the phone  → create a fresh customer.
     * - Existing row with NO password  → an incomplete / legacy OTP-only
     *   account: resume it (set the password, link Firebase, fill name /
     *   email if given) rather than duplicating.
     * - Existing row WITH a password   → already registered: refuse.
     *
     * @return array{user: User, resumed: bool}
     *
     * @throws AccountAlreadyExistsException
     */
    public function completeSignup(
        FirebaseIdentity $phoneIdentity,
        string $plainPassword,
        ?string $name = null,
        ?string $verifiedEmail = null,
    ): array {
        $phone = PhoneNumber::national((string) $phoneIdentity->phoneNumber);
        if ($phone === '') {
            throw FirebaseAuthException::invalid('token carried no phone number');
        }

        if ($verifiedEmail !== null) {
            $emailOwner = $this->findByEmail($verifiedEmail);
            if ($emailOwner && ! $this->phoneMatches($emailOwner, $phone)) {
                throw new AccountAlreadyExistsException('email');
            }
        }

        return DB::transaction(function () use ($phone, $plainPassword, $name, $verifiedEmail, $phoneIdentity) {
            $existing = User::where('phone', $phone)->lockForUpdate()->first();

            if ($existing && filled($existing->password)) {
                throw new AccountAlreadyExistsException('mobile number');
            }

            $user = $existing ?? new User([
                'uuid' => (string) Str::uuid(),
                'phone' => $phone,
                'role' => 'customer',
                'preferred_language' => 'en',
            ]);

            $user->fill([
                'name' => $name ?: ($user->name ?: 'Customer'),
                'status' => 'active',
                'password' => Hash::make($plainPassword),
                'phone_verified_at' => $user->phone_verified_at ?? now(),
                'firebase_uid' => $phoneIdentity->uid,
            ]);

            if ($verifiedEmail !== null) {
                $user->email = Str::lower(trim($verifiedEmail));
                $user->email_verified_at = now();
            }

            $user->save();

            return ['user' => $user, 'resumed' => (bool) $existing];
        });
    }

    /**
     * New customer from a Google identity plus a Firebase-verified phone,
     * created in one atomic write — an abandoned phone step before this
     * point leaves nothing persisted.
     */
    public function createFromGoogle(FirebaseIdentity $google, FirebaseIdentity $phoneIdentity, ?string $plainPassword = null): User
    {
        $phone = PhoneNumber::national((string) $phoneIdentity->phoneNumber);
        if ($phone === '') {
            throw FirebaseAuthException::invalid('phone token carried no phone number');
        }

        return DB::transaction(function () use ($google, $phoneIdentity, $phone, $plainPassword) {
            if (User::where('phone', $phone)->lockForUpdate()->exists()) {
                throw new AccountAlreadyExistsException('mobile number');
            }
            if ($google->email && User::whereRaw('LOWER(email) = ?', [Str::lower($google->email)])->exists()) {
                throw new AccountAlreadyExistsException('email');
            }

            return User::create([
                'uuid' => (string) Str::uuid(),
                'name' => $google->name ?: 'Customer',
                'phone' => $phone,
                'email' => $google->email ? Str::lower($google->email) : null,
                'password' => $plainPassword ? Hash::make($plainPassword) : null,
                'role' => 'customer',
                'status' => 'active',
                'preferred_language' => 'en',
                'phone_verified_at' => now(),
                'email_verified_at' => $google->email && $google->emailVerified ? now() : null,
                // The Google identity is the one they re-authenticate with
                // next time (the "Continue with Google" button); the phone
                // token was proof of number ownership, not the login identity.
                'firebase_uid' => $google->uid,
                'google_id' => $google->uid,
                'avatar_url' => $google->picture,
            ]);
        });
    }

    // ───────────────────────────── Mutation ───────────────────────────────

    /**
     * Attach a verified Firebase identity to an existing row. Idempotent.
     *
     * `firebase_uid` is first-write-wins: a phone-auth token and a Google
     * token for the same person carry different `sub`s unless the client
     * linked them, so the FIRST verified identity claims the column and
     * later verifications only add their provider-specific side effects
     * (google_id / avatar / phone_verified_at). This is not a takeover
     * surface — reaching here already required matching that column or
     * proving the account's own phone number.
     */
    public function linkFirebaseIdentity(User $user, FirebaseIdentity $identity): User
    {
        if (blank($user->firebase_uid)) {
            $user->firebase_uid = $identity->uid;
        }

        if ($identity->isPhoneProvider() && $identity->phoneNumber !== null) {
            $user->phone_verified_at = $user->phone_verified_at ?? now();
        }

        if ($identity->isGoogleProvider()) {
            $user->google_id = $identity->uid;
            $user->avatar_url = $user->avatar_url ?: $identity->picture;
            if ($identity->email && $identity->emailVerified && blank($user->email_verified_at)
                && Str::lower($identity->email) === Str::lower((string) $user->email)) {
                $user->email_verified_at = now();
            }
        }

        $user->save();

        return $user;
    }

    public function setPassword(User $user, string $plainPassword): void
    {
        $user->forceFill([
            'password' => Hash::make($plainPassword),
            'remember_token' => Str::random(60),
        ])->save();
    }

    public function markPhoneVerified(User $user): void
    {
        if (blank($user->phone_verified_at)) {
            $user->forceFill(['phone_verified_at' => now()])->save();
        }
    }

    // ───────────────────────────── Helpers ────────────────────────────────

    public static function isEmail(string $identifier): bool
    {
        return str_contains($identifier, '@')
            && filter_var(trim($identifier), FILTER_VALIDATE_EMAIL) !== false;
    }

    public function phoneMatches(User $user, string $phone): bool
    {
        return PhoneNumber::national((string) $user->phone) === PhoneNumber::national($phone);
    }
}
