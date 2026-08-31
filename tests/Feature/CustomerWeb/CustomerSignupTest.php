<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Customer\Auth\Signup;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Support\RebuiltAuthHelpers;
use Tests\TestCase;

/**
 * Signup — verify a mobile number (Firebase, faked here), optionally an
 * email (custom OTP), then set a password. Mobile is mandatory; email is
 * secondary. An existing password-less row for the number is RESUMED, not
 * duplicated; an existing registered row is refused.
 */
class CustomerSignupTest extends TestCase
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

    private function verifyPhone(\Livewire\Features\SupportTesting\Testable $c, string $national): void
    {
        $token = $this->firebase->issuePhoneToken($this->e164($national));
        $c->call('requestPhoneCode')->call('phoneTokenReceived', $token)->assertSet('step', 'details');
    }

    public function test_signup_via_mobile_sets_a_password_and_logs_in(): void
    {
        $phone = $this->randomPhone();

        $c = Livewire::test(Signup::class)->set('phone', $phone);
        $this->verifyPhone($c, $phone);

        $c->set('name', 'Asha Rao')
            ->set('password', 'longenough1')
            ->set('password_confirmation', 'longenough1')
            ->call('completeSignup')
            ->assertHasNoErrors()
            ->assertRedirect(route('customer.home'));

        $user = User::where('phone', $phone)->sole();
        $this->assertSame('Asha Rao', $user->name);
        $this->assertTrue(Hash::check('longenough1', $user->password));
        $this->assertNotNull($user->phone_verified_at);
        $this->assertAuthenticatedAs($user);
    }

    public function test_signup_can_also_verify_and_attach_an_email(): void
    {
        $phone = $this->randomPhone();
        $email = 'asha+'.uniqid().'@example.com';

        $c = Livewire::test(Signup::class)->set('phone', $phone);
        $this->verifyPhone($c, $phone);

        $c->set('email', $email)->call('sendEmailCode')->assertSet('emailCodeSent', true);
        $code = $this->emailOtpCodeFor($email);
        $c->set('emailCode', $code)->call('verifyEmailCode')->assertSet('verifiedEmail', $email);

        $c->set('name', 'Asha')
            ->set('password', 'longenough1')
            ->set('password_confirmation', 'longenough1')
            ->call('completeSignup')
            ->assertRedirect(route('customer.home'));

        $user = User::where('phone', $phone)->sole();
        $this->assertSame($email, $user->email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_a_supplied_email_that_is_not_verified_blocks_completion(): void
    {
        $phone = $this->randomPhone();
        $c = Livewire::test(Signup::class)->set('phone', $phone);
        $this->verifyPhone($c, $phone);

        $c->set('email', 'unverified@example.com')
            ->set('name', 'Asha')
            ->set('password', 'longenough1')
            ->set('password_confirmation', 'longenough1')
            ->call('completeSignup')
            ->assertSet('error', 'Verify your email address, or leave the field blank.');

        $this->assertSame(0, User::count());
    }

    public function test_completion_is_blocked_until_the_mobile_is_verified(): void
    {
        Livewire::test(Signup::class)
            ->set('name', 'Asha')
            ->set('password', 'longenough1')
            ->set('password_confirmation', 'longenough1')
            ->call('completeSignup')
            ->assertSet('error', 'Verify your mobile number first.');

        $this->assertSame(0, User::count());
    }

    public function test_a_weak_password_is_rejected(): void
    {
        $phone = $this->randomPhone();
        $c = Livewire::test(Signup::class)->set('phone', $phone);
        $this->verifyPhone($c, $phone);

        $c->set('name', 'Asha')
            ->set('password', 'short')
            ->set('password_confirmation', 'short')
            ->call('completeSignup')
            ->assertHasErrors(['password']);

        $this->assertSame(0, User::count());
    }

    public function test_a_fully_registered_mobile_is_refused_not_overwritten(): void
    {
        $existing = $this->passwordCustomer('original-pass');

        $c = Livewire::test(Signup::class)->set('phone', $existing->phone);
        $this->verifyPhone($c, $existing->phone);

        $c->set('name', 'Impostor')
            ->set('password', 'newpassword1')
            ->set('password_confirmation', 'newpassword1')
            ->call('completeSignup')
            ->assertSet('error', fn ($e) => str_contains($e, 'already exists'));

        $existing->refresh();
        $this->assertTrue(Hash::check('original-pass', $existing->password), 'The existing password must be untouched.');
        $this->assertSame('Pw Customer', $existing->name);
    }

    public function test_an_incomplete_passwordless_account_is_resumed_not_duplicated(): void
    {
        $legacy = $this->legacyPasswordlessCustomer();

        $c = Livewire::test(Signup::class)->set('phone', $legacy->phone);
        $this->verifyPhone($c, $legacy->phone);

        $c->set('name', 'Reclaimed')
            ->set('password', 'brandnew123')
            ->set('password_confirmation', 'brandnew123')
            ->call('completeSignup')
            ->assertRedirect(route('customer.home'));

        $this->assertSame(1, User::where('phone', $legacy->phone)->count());
        $legacy->refresh();
        $this->assertTrue(Hash::check('brandnew123', $legacy->password));
        $this->assertSame($legacy->id, auth()->id());
    }
}
