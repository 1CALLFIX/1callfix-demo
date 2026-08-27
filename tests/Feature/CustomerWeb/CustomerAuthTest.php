<?php

namespace Tests\Feature\CustomerWeb;

use App\Contracts\SmsAdapter;
use App\Livewire\Customer\Auth\Login;
use App\Models\Otp;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Support\CapturingSmsAdapter;
use Tests\TestCase;

/**
 * Customer web (session) OTP login — Phase B.
 *
 * Uses the SAME bound test SmsAdapter tests/Feature/Auth/AuthOtpTest.php
 * uses, so the code being verified here is the real code the real
 * OtpService generated and sent. Nothing about OTP verification is mocked;
 * only the external SMS send is captured.
 */
class CustomerAuthTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;

    private CapturingSmsAdapter $sms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sms = new CapturingSmsAdapter;
        $this->app->instance(SmsAdapter::class, $this->sms);
        RateLimiter::clear('');
    }

    private function phone(): string
    {
        return '9'.fake()->unique()->numerify('#########');
    }

    // ============================ Screen ============================

    public function test_login_screen_renders_for_a_guest(): void
    {
        $this->get(route('customer.login'))
            ->assertOk()
            ->assertSeeText('Mobile number');
    }

    public function test_login_screen_redirects_an_already_authenticated_customer(): void
    {
        $this->actingAs($this->makeCustomer())
            ->get(route('customer.login'))
            ->assertRedirect(route('customer.home'));
    }

    // ============================ Happy path ============================

    public function test_a_new_customer_is_provisioned_and_logged_into_a_session(): void
    {
        $phone = $this->phone();

        $component = Livewire::test(Login::class)
            ->set('phone', $phone)
            ->call('requestCode')
            ->assertSet('step', 'code')
            ->assertSet('error', '');

        $code = $this->sms->lastCodeTo($phone);
        $this->assertNotNull($code, 'The real OtpService must have sent a real code.');

        $component->set('code', $code)
            ->call('verifyCode')
            ->assertRedirect(route('customer.home'));

        $this->assertDatabaseHas('users', ['phone' => $phone, 'role' => 'customer', 'status' => 'active']);
        $this->assertAuthenticatedAs(User::where('phone', $phone)->firstOrFail());
    }

    public function test_login_reuses_an_existing_account_rather_than_duplicating_it(): void
    {
        $customer = $this->makeCustomer();

        Livewire::test(Login::class)
            ->set('phone', $customer->phone)
            ->call('requestCode')
            ->set('code', $this->sms->lastCodeTo($customer->phone))
            ->call('verifyCode')
            ->assertRedirect(route('customer.home'));

        $this->assertAuthenticatedAs($customer);
        $this->assertSame(1, User::where('phone', $customer->phone)->count());
    }

    /**
     * The plaintext code must never be reachable from component state — a
     * Livewire component's public properties are serialised to the browser
     * on every round trip.
     */
    public function test_the_plaintext_code_is_never_exposed_in_component_state(): void
    {
        $phone = $this->phone();

        $component = Livewire::test(Login::class)->set('phone', $phone)->call('requestCode');
        $code = $this->sms->lastCodeTo($phone);

        $component->assertDontSee($code);
        $this->assertSame('', $component->get('code'));
    }

    public function test_the_otp_is_stored_hashed_not_in_plaintext(): void
    {
        $phone = $this->phone();
        Livewire::test(Login::class)->set('phone', $phone)->call('requestCode');

        $otp = Otp::where('phone', $phone)->firstOrFail();
        $this->assertNotSame($this->sms->lastCodeTo($phone), $otp->code_hash);
    }

    // ============================ Error paths ============================

    public function test_an_invalid_phone_number_is_rejected_before_any_otp_is_sent(): void
    {
        Livewire::test(Login::class)
            ->set('phone', 'not-a-number')
            ->call('requestCode')
            ->assertHasErrors('phone')
            ->assertSet('step', 'phone');

        $this->assertSame(0, Otp::count());
    }

    public function test_an_incorrect_code_is_rejected_and_allows_a_retry(): void
    {
        $phone = $this->phone();

        $component = Livewire::test(Login::class)->set('phone', $phone)->call('requestCode');
        $realCode = $this->sms->lastCodeTo($phone);

        $component->set('code', '000000')
            ->call('verifyCode')
            ->assertSet('error', 'Incorrect code.')
            ->assertSet('code', '');

        $this->assertGuest();

        // A wrong attempt must not consume the pending code.
        $component->set('code', $realCode)
            ->call('verifyCode')
            ->assertRedirect(route('customer.home'));

        $this->assertAuthenticated();
    }

    public function test_an_expired_code_is_reported_as_expired(): void
    {
        $phone = $this->phone();
        $component = Livewire::test(Login::class)->set('phone', $phone)->call('requestCode');
        $code = $this->sms->lastCodeTo($phone);

        Otp::where('phone', $phone)->update(['expires_at' => now()->subMinute()]);

        $component->set('code', $code)
            ->call('verifyCode')
            ->assertSet('error', 'This code has expired. Request a new one.');

        $this->assertGuest();
    }

    public function test_repeated_wrong_codes_lock_the_otp_out(): void
    {
        $phone = $this->phone();
        $component = Livewire::test(Login::class)->set('phone', $phone)->call('requestCode');
        $realCode = $this->sms->lastCodeTo($phone);

        for ($i = 0; $i < 5; $i++) {
            $component->set('code', '000000')->call('verifyCode');
        }

        // Even the CORRECT code must now be refused — the lockout lives in
        // OtpService and is not something this screen can talk past.
        $component->set('code', $realCode)
            ->call('verifyCode')
            ->assertSet('error', 'Too many incorrect attempts. Request a new code.');

        $this->assertGuest();
    }

    public function test_resend_before_the_cooldown_elapses_is_refused_by_the_service(): void
    {
        $phone = $this->phone();

        $component = Livewire::test(Login::class)->set('phone', $phone)->call('requestCode');
        $component->call('resendCode');

        $this->assertStringContainsString('before requesting another code', $component->get('error'));
    }

    public function test_resend_after_the_cooldown_issues_a_new_code_and_invalidates_the_old_one(): void
    {
        $phone = $this->phone();
        $component = Livewire::test(Login::class)->set('phone', $phone)->call('requestCode');
        $firstCode = $this->sms->lastCodeTo($phone);

        Setting::set('auth.otp_resend_cooldown_seconds', '0');
        $component->call('resendCode')->assertSet('error', '');
        $secondCode = $this->sms->lastCodeTo($phone);

        $this->assertNotSame($firstCode, $secondCode);

        $component->set('code', $firstCode)
            ->call('verifyCode')
            ->assertSet('error', 'Incorrect code.');

        $this->assertGuest();
    }

    public function test_changing_the_phone_number_returns_to_step_one_and_clears_the_code(): void
    {
        $phone = $this->phone();

        Livewire::test(Login::class)
            ->set('phone', $phone)
            ->call('requestCode')
            ->set('code', '123456')
            ->call('changePhone')
            ->assertSet('step', 'phone')
            ->assertSet('code', '')
            ->assertSet('error', '');
    }

    // ============================ Rate limiting ============================

    /**
     * Livewire actions all share the single /livewire/update endpoint, so
     * routes/api.php's throttle: middleware does not protect this screen.
     * Without the component's own RateLimiter, this would be an unthrottled
     * way to force real SMS cost.
     */
    public function test_otp_requests_are_rate_limited(): void
    {
        $phone = $this->phone();
        Setting::set('auth.otp_resend_cooldown_seconds', '0');

        $component = Livewire::test(Login::class)->set('phone', $phone);

        for ($i = 0; $i < 5; $i++) {
            $component->call('requestCode')->assertSet('error', '');
        }

        $component->call('requestCode');
        $this->assertStringContainsString('Too many attempts', $component->get('error'));
    }

    public function test_verify_attempts_are_rate_limited(): void
    {
        $phone = $this->phone();
        Setting::set('auth.otp_max_attempts', '999'); // isolate the LIVEWIRE limiter from OtpService's own lockout

        $component = Livewire::test(Login::class)->set('phone', $phone)->call('requestCode');

        for ($i = 0; $i < 10; $i++) {
            $component->set('code', '000000')->call('verifyCode');
        }

        $component->set('code', '000000')->call('verifyCode');
        $this->assertStringContainsString('Too many attempts', $component->get('error'));
    }

    // ============================ Session lifecycle ============================

    public function test_logout_ends_the_session(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer)
            ->post(route('customer.logout'))
            ->assertRedirect(route('customer.home'));

        $this->assertGuest();
    }

    public function test_logout_is_not_reachable_by_get(): void
    {
        // A GET logout is CSRF-forgeable by any third-party <img> or prefetch.
        $this->actingAs($this->makeCustomer())
            ->get('/logout')
            ->assertStatus(405);

        $this->assertAuthenticated();
    }

    /**
     * Phone numbers differing only by presentational spacing/dashes must
     * resolve to ONE account, not several.
     */
    public function test_spacing_and_dashes_in_the_phone_number_resolve_to_one_account(): void
    {
        $phone = '9876543210';

        Livewire::test(Login::class)
            ->set('phone', '98765-43210')
            ->call('requestCode')
            ->set('code', $this->sms->lastCodeTo($phone))
            ->call('verifyCode')
            ->assertRedirect(route('customer.home'));

        $this->assertSame(1, User::where('phone', $phone)->count());
    }
}
