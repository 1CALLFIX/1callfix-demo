<?php

namespace Tests\Feature\Support;

use App\Contracts\FirebaseTokenVerifier;
use App\Models\User;
use App\Notifications\EmailOtpNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Support\FakeFirebaseTokenVerifier;

/**
 * Shared setup for the rebuilt-auth test suites: a bound
 * FakeFirebaseTokenVerifier (no network, no real JWT) and helpers to read
 * the numeric code out of a faked EmailOtpNotification.
 *
 * Call bindFakeFirebase() and Notification::fake() from setUp().
 */
trait RebuiltAuthHelpers
{
    protected FakeFirebaseTokenVerifier $firebase;

    protected function bindFakeFirebase(): void
    {
        $this->firebase = new FakeFirebaseTokenVerifier;
        $this->app->instance(FirebaseTokenVerifier::class, $this->firebase);
    }

    protected function randomPhone(): string
    {
        return '9'.fake()->unique()->numerify('#########');
    }

    protected function e164(string $national): string
    {
        return '+91'.preg_replace('/\D/', '', $national);
    }

    /** Reads the code from the most recent faked EmailOtpNotification to $email. */
    protected function emailOtpCodeFor(string $email): string
    {
        $captured = null;

        Notification::assertSentOnDemand(
            EmailOtpNotification::class,
            function ($notification, $channels, $notifiable) use (&$captured, $email) {
                if (($notifiable->routes['mail'] ?? null) === $email) {
                    $captured = $notification->code;

                    return true;
                }

                return false;
            }
        );

        $this->assertNotNull($captured, "No email OTP notification captured for {$email}.");

        return $captured;
    }

    /** A pre-rebuild OTP-only customer: real phone, no password. */
    protected function legacyPasswordlessCustomer(?string $phone = null): User
    {
        return User::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Legacy Customer',
            'phone' => $phone ?? $this->randomPhone(),
            'role' => 'customer',
            'status' => 'active',
        ]);
    }

    /** A fully migrated customer with a known password. */
    protected function passwordCustomer(string $password = 'secret1234', array $overrides = []): User
    {
        return User::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'name' => 'Pw Customer',
            'phone' => $this->randomPhone(),
            'role' => 'customer',
            'status' => 'active',
            'password' => bcrypt($password),
            'phone_verified_at' => now(),
        ], $overrides));
    }
}
