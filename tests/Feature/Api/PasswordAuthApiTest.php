<?php

namespace Tests\Feature\Api;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Support\RebuiltAuthHelpers;
use Tests\TestCase;

/**
 * POST /api/auth/password and the password-reset endpoints — the token
 * flow after the auth rebuild. Login by OTP is gone; a Sanctum token is
 * issued only for a correct password or a verified Firebase token.
 */
class PasswordAuthApiTest extends TestCase
{
    use RebuiltAuthHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeFirebase();
        Notification::fake();
        Setting::set('auth.otp_resend_cooldown_seconds', '0');
    }

    public function test_correct_password_returns_a_token(): void
    {
        $user = $this->passwordCustomer('good-password-1', ['email' => 'api@example.com', 'email_verified_at' => now()]);

        $this->postJson('/api/auth/password', [
            'identifier' => 'api@example.com',
            'password' => 'good-password-1',
            'actor_type' => 'customer',
        ])->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonStructure(['token', 'actor_type', 'user' => ['id', 'name', 'phone', 'email', 'role']]);
    }

    public function test_wrong_password_is_401_with_no_token(): void
    {
        $user = $this->passwordCustomer('good-password-1');

        $this->postJson('/api/auth/password', [
            'identifier' => $user->phone, 'password' => 'nope', 'actor_type' => 'customer',
        ])->assertStatus(401)->assertJsonMissing(['token']);
    }

    public function test_a_passwordless_account_is_routed_to_setup_not_logged_in(): void
    {
        $legacy = $this->legacyPasswordlessCustomer();

        $this->postJson('/api/auth/password', [
            'identifier' => $legacy->phone, 'password' => 'anything123', 'actor_type' => 'customer',
        ])->assertStatus(409)->assertJsonPath('needs_password_setup', true);
    }

    public function test_password_login_is_throttled_per_identifier(): void
    {
        $user = $this->passwordCustomer('good-password-1');

        for ($i = 0; $i < 6; $i++) {
            $r = $this->postJson('/api/auth/password', [
                'identifier' => $user->phone, 'password' => 'bad', 'actor_type' => 'customer',
            ]);
        }

        $r->assertStatus(429);
    }

    public function test_password_reset_by_email_invalidates_the_old_password_and_tokens(): void
    {
        $user = $this->passwordCustomer('old-one-11', ['email' => 'reset@example.com', 'email_verified_at' => now()]);
        $staleToken = $user->createToken('old-device')->plainTextToken;

        $this->postJson('/api/auth/password/forgot', ['identifier' => 'reset@example.com'])
            ->assertOk()->assertJsonPath('channel', 'email');

        $code = $this->emailOtpCodeFor('reset@example.com');

        $this->postJson('/api/auth/password/reset', [
            'identifier' => 'reset@example.com', 'code' => $code, 'new_password' => 'fresh-one-22',
        ])->assertOk();

        // Old password rejected, new accepted.
        $this->postJson('/api/auth/password', ['identifier' => 'reset@example.com', 'password' => 'old-one-11', 'actor_type' => 'customer'])
            ->assertStatus(401);
        $this->postJson('/api/auth/password', ['identifier' => 'reset@example.com', 'password' => 'fresh-one-22', 'actor_type' => 'customer'])
            ->assertOk();

        // Stale token revoked.
        $this->withToken($staleToken)->getJson('/api/user')->assertStatus(401);
    }

    public function test_password_reset_by_mobile_uses_a_firebase_token(): void
    {
        $user = $this->passwordCustomer('old-one-11');

        $this->postJson('/api/auth/password/forgot', ['identifier' => $user->phone])
            ->assertOk()->assertJsonPath('channel', 'firebase');

        $token = $this->firebase->issuePhoneToken($this->e164($user->phone));

        $this->postJson('/api/auth/password/reset', [
            'identifier' => $user->phone, 'id_token' => $token, 'new_password' => 'fresh-one-22',
        ])->assertOk();

        $this->postJson('/api/auth/password', ['identifier' => $user->phone, 'password' => 'fresh-one-22', 'actor_type' => 'customer'])
            ->assertOk();
    }
}
