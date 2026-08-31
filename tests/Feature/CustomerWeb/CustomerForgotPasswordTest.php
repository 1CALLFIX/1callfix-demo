<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Customer\Auth\ForgotPassword;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Support\RebuiltAuthHelpers;
use Tests\TestCase;

/**
 * Forgot password — identifier → OTP via the matching channel (custom
 * email code / faked Firebase phone token) → set a new password. Reuses
 * the Task 1 infrastructure; no separate mechanism.
 */
class CustomerForgotPasswordTest extends TestCase
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

    public function test_reset_via_email_replaces_the_password(): void
    {
        $user = $this->passwordCustomer('old-password-9', ['email' => 'reset-me@example.com', 'email_verified_at' => now()]);

        $c = Livewire::test(ForgotPassword::class)
            ->set('identifier', 'reset-me@example.com')
            ->call('submitIdentifier')
            ->assertSet('step', 'email_code');

        $code = $this->emailOtpCodeFor('reset-me@example.com');

        $c->set('code', $code)->call('verifyEmailCode')->assertSet('step', 'new_password')
            ->set('password', 'brand-new-42')
            ->set('password_confirmation', 'brand-new-42')
            ->call('setNewPassword')
            ->assertRedirect(route('customer.login'));

        $user->refresh();
        $this->assertTrue(Hash::check('brand-new-42', $user->password));
        $this->assertFalse(Hash::check('old-password-9', $user->password), 'The old password must stop working.');
    }

    public function test_reset_via_mobile_replaces_the_password(): void
    {
        $user = $this->passwordCustomer('old-password-9');

        $c = Livewire::test(ForgotPassword::class)
            ->set('identifier', $user->phone)
            ->call('submitIdentifier')
            ->assertSet('step', 'verify_phone');

        $token = $this->firebase->issuePhoneToken($this->e164($user->phone));

        $c->call('phoneTokenReceived', $token)->assertSet('step', 'new_password')
            ->set('password', 'brand-new-42')
            ->set('password_confirmation', 'brand-new-42')
            ->call('setNewPassword')
            ->assertRedirect(route('customer.login'));

        $this->assertTrue(Hash::check('brand-new-42', $user->fresh()->password));
    }

    public function test_a_wrong_email_code_is_rejected(): void
    {
        $this->passwordCustomer('x', ['email' => 'x@example.com', 'email_verified_at' => now()]);

        Livewire::test(ForgotPassword::class)
            ->set('identifier', 'x@example.com')
            ->call('submitIdentifier')
            ->set('code', '000000')
            ->call('verifyEmailCode')
            ->assertSet('error', 'Incorrect code.')
            ->assertSet('step', 'email_code');
    }

    public function test_an_expired_email_code_is_rejected(): void
    {
        $this->passwordCustomer('x', ['email' => 'y@example.com', 'email_verified_at' => now()]);

        $c = Livewire::test(ForgotPassword::class)->set('identifier', 'y@example.com')->call('submitIdentifier');
        $code = $this->emailOtpCodeFor('y@example.com');

        \App\Models\Otp::where('identifier', 'y@example.com')->update(['expires_at' => now()->subMinute()]);

        $c->set('code', $code)->call('verifyEmailCode')
            ->assertSet('error', 'This code has expired. Request a new one.')
            ->assertSet('step', 'email_code');
    }

    public function test_a_mobile_token_that_does_not_match_is_rejected(): void
    {
        $user = $this->passwordCustomer('x');

        $c = Livewire::test(ForgotPassword::class)->set('identifier', $user->phone)->call('submitIdentifier');
        $wrongToken = $this->firebase->issuePhoneToken($this->e164($this->randomPhone()));

        $c->call('phoneTokenReceived', $wrongToken)
            ->assertSet('error', 'That verification does not match this mobile number.')
            ->assertSet('step', 'verify_phone');
    }

    public function test_reset_is_rate_limited_per_identifier(): void
    {
        $user = $this->passwordCustomer('x', ['email' => 'rl@example.com', 'email_verified_at' => now()]);
        $c = Livewire::test(ForgotPassword::class)->set('identifier', 'rl@example.com');

        for ($i = 0; $i < 6; $i++) {
            $c->call('submitIdentifier');
        }

        $this->assertStringContainsString('Too many attempts', $c->get('error'));
    }

    public function test_email_reset_does_not_disclose_whether_the_account_exists(): void
    {
        Livewire::test(ForgotPassword::class)
            ->set('identifier', 'nobody-here@example.com')
            ->call('submitIdentifier')
            ->assertSet('step', 'email_code')
            ->assertSet('status', 'If that email is registered, a reset code has been sent.');
    }
}
