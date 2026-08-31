<?php

namespace Tests\Feature\Auth;

use App\Models\Otp;
use App\Models\Setting;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Support\RebuiltAuthHelpers;
use Tests\TestCase;

/**
 * The DEMOTED POST /api/auth/otp/{request,verify} contract after the auth
 * rebuild (docs/auth-otp-consumer-audit.md):
 *
 *  • OTP is no longer a login mechanism — verify NEVER returns a token.
 *  • The channel is EMAIL only (an `identifier` that is an email address);
 *    phone codes now come from Firebase client-side.
 *  • `purpose` is required and restricted to email_verify / password_reset.
 *  • A legacy `{ phone, actor_type }` login-shaped call is rejected 422.
 *
 * The OTP engine's own mechanics (hash-at-rest, expiry, lockout, cooldown)
 * are covered by EmailOtpTest; this file is the HTTP-contract guard.
 */
class AuthOtpTest extends TestCase
{
    use RebuiltAuthHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Setting::set('auth.otp_resend_cooldown_seconds', '0');
    }

    public function test_request_then_verify_an_email_code_returns_verified_true_and_no_token(): void
    {
        $this->postJson('/api/auth/otp/request', [
            'identifier' => 'user@example.com',
            'purpose' => OtpService::PURPOSE_EMAIL_VERIFY,
        ])->assertOk()->assertJsonMissing(['token']);

        $code = $this->emailOtpCodeFor('user@example.com');

        $this->postJson('/api/auth/otp/verify', [
            'identifier' => 'user@example.com',
            'purpose' => OtpService::PURPOSE_EMAIL_VERIFY,
            'code' => $code,
        ])->assertOk()->assertExactJson(['verified' => true]);
    }

    public function test_a_wrong_code_is_422_with_no_token(): void
    {
        $this->postJson('/api/auth/otp/request', ['identifier' => 'user@example.com', 'purpose' => OtpService::PURPOSE_PASSWORD_RESET])->assertOk();

        $this->postJson('/api/auth/otp/verify', [
            'identifier' => 'user@example.com', 'purpose' => OtpService::PURPOSE_PASSWORD_RESET, 'code' => '000000',
        ])->assertStatus(422)->assertJsonMissing(['token', 'verified']);
    }

    public function test_purpose_is_required_and_restricted(): void
    {
        $this->postJson('/api/auth/otp/request', ['identifier' => 'user@example.com'])
            ->assertStatus(422)->assertJsonValidationErrors(['purpose']);

        $this->postJson('/api/auth/otp/request', ['identifier' => 'user@example.com', 'purpose' => 'login'])
            ->assertStatus(422)->assertJsonValidationErrors(['purpose']);
    }

    public function test_a_non_email_identifier_is_rejected(): void
    {
        $this->postJson('/api/auth/otp/request', [
            'identifier' => '9876543210', 'purpose' => OtpService::PURPOSE_EMAIL_VERIFY,
        ])->assertStatus(422);
    }

    public function test_a_legacy_phone_login_shaped_call_is_rejected(): void
    {
        // The pre-rebuild mobile-client contract: { phone, actor_type }, no purpose.
        $this->postJson('/api/auth/otp/request', ['phone' => '9876543210', 'actor_type' => 'customer'])
            ->assertStatus(422);

        $this->postJson('/api/auth/otp/verify', ['phone' => '9876543210', 'actor_type' => 'customer', 'code' => '123456'])
            ->assertStatus(422);
    }

    public function test_request_is_enumeration_safe(): void
    {
        // Identical response whether or not any account uses the address.
        $a = $this->postJson('/api/auth/otp/request', ['identifier' => 'exists@example.com', 'purpose' => OtpService::PURPOSE_PASSWORD_RESET]);
        $b = $this->postJson('/api/auth/otp/request', ['identifier' => 'nobody@example.com', 'purpose' => OtpService::PURPOSE_PASSWORD_RESET]);

        $a->assertOk();
        $b->assertOk();
        $this->assertSame($a->json(), $b->json());
    }

    public function test_request_is_throttled(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $r = $this->postJson('/api/auth/otp/request', [
                'identifier' => 'rl@example.com', 'purpose' => OtpService::PURPOSE_EMAIL_VERIFY,
            ]);
        }
        $r->assertStatus(429);
    }

    public function test_a_verified_code_cannot_be_replayed(): void
    {
        $this->postJson('/api/auth/otp/request', ['identifier' => 'once@example.com', 'purpose' => OtpService::PURPOSE_EMAIL_VERIFY])->assertOk();
        $code = $this->emailOtpCodeFor('once@example.com');

        $this->postJson('/api/auth/otp/verify', ['identifier' => 'once@example.com', 'purpose' => OtpService::PURPOSE_EMAIL_VERIFY, 'code' => $code])->assertOk();
        $this->postJson('/api/auth/otp/verify', ['identifier' => 'once@example.com', 'purpose' => OtpService::PURPOSE_EMAIL_VERIFY, 'code' => $code])->assertStatus(410);

        $this->assertSame(Otp::STATUS_VERIFIED, Otp::where('identifier', 'once@example.com')->latest('id')->first()->status);
    }
}
