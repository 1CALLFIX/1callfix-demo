<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Customer\Auth\Login;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\RebuiltAuthHelpers;
use Tests\TestCase;

/**
 * Customer web login after the auth rebuild — identifier (mobile or email)
 * + PASSWORD. The recurring OTP "enter your code" login step is gone; it
 * survives only for signup / reset / Google linking, tested elsewhere.
 */
class CustomerAuthTest extends TestCase
{
    use BookingFixtureHelpers;
    use RebuiltAuthHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeFirebase();
        Notification::fake();
        RateLimiter::clear('');
    }

    // ───────────────────────────── Screen ─────────────────────────────────

    public function test_login_screen_renders_for_a_guest(): void
    {
        $this->get(route('customer.login'))
            ->assertOk()
            ->assertSeeText('Mobile number or email')
            ->assertSeeText('Password')
            ->assertDontSeeText('enter your code', false)
            ->assertDontSeeText('We\'ll text you a verification code');
    }

    public function test_login_screen_redirects_an_already_authenticated_customer(): void
    {
        $this->actingAs($this->makeCustomer())
            ->get(route('customer.login'))
            ->assertRedirect(route('customer.home'));
    }

    // ───────────────────────────── Happy path ─────────────────────────────

    public function test_a_customer_signs_in_with_mobile_and_password(): void
    {
        $user = $this->passwordCustomer('correct-horse-1');

        Livewire::test(Login::class)
            ->set('identifier', $user->phone)
            ->set('password', 'correct-horse-1')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('customer.home'));

        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_a_customer_signs_in_with_email_and_password(): void
    {
        $user = $this->passwordCustomer('correct-horse-2', ['email' => 'jo@example.com', 'email_verified_at' => now()]);

        Livewire::test(Login::class)
            ->set('identifier', 'JO@example.com') // case-insensitive
            ->set('password', 'correct-horse-2')
            ->call('login')
            ->assertRedirect(route('customer.home'));

        $this->assertAuthenticatedAs($user->fresh());
    }

    // ───────────────────────────── Failures ───────────────────────────────

    public function test_a_wrong_password_is_rejected_and_does_not_authenticate(): void
    {
        $user = $this->passwordCustomer('right-password');

        Livewire::test(Login::class)
            ->set('identifier', $user->phone)
            ->set('password', 'wrong-password')
            ->call('login')
            ->assertSet('error', 'Those details do not match an account.')
            ->assertSet('password', '');

        $this->assertGuest();
    }

    public function test_an_unknown_identifier_is_rejected_generically(): void
    {
        Livewire::test(Login::class)
            ->set('identifier', $this->randomPhone())
            ->set('password', 'whatever12')
            ->call('login')
            ->assertSet('error', 'Those details do not match an account.');

        $this->assertGuest();
    }

    public function test_an_account_with_no_password_is_routed_to_the_migration_flow(): void
    {
        $legacy = $this->legacyPasswordlessCustomer();

        Livewire::test(Login::class)
            ->set('identifier', $legacy->phone)
            ->set('password', 'anything123')
            ->call('login')
            ->assertRedirect(route('customer.auth.migrate', ['identifier' => $legacy->phone]));

        $this->assertGuest();
    }

    // ───────────────────────────── Throttling ─────────────────────────────

    public function test_repeated_failures_are_throttled_per_identifier(): void
    {
        $user = $this->passwordCustomer('the-real-one');

        $component = Livewire::test(Login::class)->set('identifier', $user->phone);

        // The component blanks `password` after each wrong attempt, so it is
        // re-set every iteration — otherwise call 2+ would fail validation,
        // not auth, and never reach the limiter.
        for ($i = 0; $i < 6; $i++) {
            $component->set('password', 'nope')->call('login');
        }

        $this->assertStringContainsString('Too many attempts', $component->get('error'));

        // Even the correct password is refused while throttled.
        $component->set('password', 'the-real-one')->call('login');
        $this->assertGuest();
    }

    // ───────────────────────────── Session ────────────────────────────────

    public function test_logout_ends_the_session(): void
    {
        $this->actingAs($this->makeCustomer())
            ->post(route('customer.logout'))
            ->assertRedirect(route('customer.home'));

        $this->assertGuest();
    }

    public function test_logout_is_not_reachable_by_get(): void
    {
        $this->actingAs($this->makeCustomer())->get('/logout')->assertStatus(405);
        $this->assertAuthenticated();
    }

    public function test_google_verified_token_for_a_linked_account_signs_in(): void
    {
        $user = $this->passwordCustomer('x', ['email' => 'g@example.com', 'firebase_uid' => 'guid-linked']);
        $token = $this->firebase->issueGoogleToken('g@example.com', 'G', 'guid-linked');

        Livewire::test(Login::class)
            ->call('continueWithGoogle', $token)
            ->assertRedirect(route('customer.home'));

        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_google_verified_token_for_a_new_identity_hands_off_to_the_mobile_step(): void
    {
        $token = $this->firebase->issueGoogleToken('new@example.com', 'New Person', 'guid-new');

        Livewire::test(Login::class)
            ->call('continueWithGoogle', $token)
            ->assertRedirect(route('customer.auth.google'));

        $this->assertGuest();
        $this->assertSame('guid-new', session('auth.google.uid'));
        $this->assertSame(0, User::count());
    }
}
