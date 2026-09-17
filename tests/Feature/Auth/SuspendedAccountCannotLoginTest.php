<?php

namespace Tests\Feature\Auth;

use App\Livewire\Auth\Login as AdminLogin;
use App\Livewire\Customer\Auth\GoogleAuth;
use App\Livewire\Customer\Auth\Login as CustomerLogin;
use App\Livewire\Provider\Auth\Login as ProviderLogin;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\RebuiltAuthHelpers;
use Tests\TestCase;

/**
 * Enable/Disable Audit, item 3 — THE test that proves the audit's core
 * finding is actually fixed, not just documented: users.status was written
 * in exactly one place in this codebase (Customers\Show::toggleSuspended())
 * and read/enforced in ZERO places — a "suspended" customer could still log
 * in and do everything a normal customer could. This suite proves that is
 * no longer true, across every real place a session gets established:
 *
 *   - the admin panel login (Auth\Login::submit())
 *   - EnsureHasAdminAccess middleware (defense in depth — an account
 *     suspended mid-session is caught on its next /admin request too)
 *   - the customer web password login (Customer\Auth\Login::login())
 *   - the customer web Google login, both entry points (Customer\Auth\
 *     Login::continueWithGoogle() for an already-linked account, and
 *     Customer\Auth\GoogleAuth for both its "already linked" and
 *     "completing verification" paths)
 *   - the provider web password login (Provider\Auth\Login::login()) —
 *     LAUNCH-002/003 follow-up: the original f4d8d12 fix never covered
 *     this surface, since this component didn't exist in its current
 *     one-page form at the time.
 *
 * API auth (/api/auth/password, /api/auth/firebase) is covered separately
 * in tests/Feature/Api/SuspendedAccountApiAuthTest.php.
 *
 * Paired with a regression check per surface: an ordinary ACTIVE account
 * (the default status) must be completely unaffected — only status =
 * suspended blocks anything.
 */
class SuspendedAccountCannotLoginTest extends TestCase
{
    use RefreshDatabase;
    use RebuiltAuthHelpers;
    use BookingFixtureHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeFirebase();
    }

    // ============================== Admin panel login ==============================

    private function makeAdminWithPassword(string $status): User
    {
        return User::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Admin User',
            'email' => Str::random(10).'@example.test',
            'phone' => '9'.fake()->unique()->numerify('#########'),
            'password' => Hash::make('correct-password'),
            'role' => 'super_admin',
            'status' => $status,
        ]);
    }

    public function test_a_suspended_admin_cannot_log_in(): void
    {
        $user = $this->makeAdminWithPassword('suspended');

        Livewire::test(AdminLogin::class)
            ->set('email', $user->email)
            ->set('password', 'correct-password')
            ->call('submit')
            ->assertSet('error', 'This account has been suspended. Contact your administrator.');

        $this->assertFalse(Auth::check());
    }

    /** Regression: an untouched super_admin (status defaults to active) logs in exactly as before this fix. */
    public function test_an_active_admin_still_logs_in_normally(): void
    {
        $user = $this->makeAdminWithPassword('active');

        Livewire::test(AdminLogin::class)
            ->set('email', $user->email)
            ->set('password', 'correct-password')
            ->call('submit')
            ->assertRedirect(route('admin.dashboard'));

        $this->assertTrue(Auth::check());
        $this->assertSame($user->id, Auth::id());
    }

    // ============================== EnsureHasAdminAccess middleware ==============================

    public function test_middleware_blocks_a_suspended_admin_even_with_an_already_established_session(): void
    {
        $user = $this->makeAdminWithPassword('suspended');

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertForbidden();
    }

    /** Regression: an active admin's normal request through the same middleware is unaffected. */
    public function test_middleware_admits_an_active_admin_as_before(): void
    {
        $user = $this->makeAdminWithPassword('active');

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertOk();
    }

    // ============================== Customer web password login ==============================

    public function test_a_suspended_customer_cannot_log_in_with_password(): void
    {
        $user = $this->passwordCustomer('correct-horse', ['status' => 'suspended']);

        Livewire::test(CustomerLogin::class)
            ->set('identifier', $user->phone)
            ->set('password', 'correct-horse')
            ->call('login')
            ->assertSet('error', 'This account has been suspended. Contact support for help.');

        $this->assertFalse(Auth::guard('web')->check());
    }

    /** Regression: an untouched (active) customer logs in exactly as before this fix. */
    public function test_an_active_customer_still_logs_in_with_password_as_before(): void
    {
        $user = $this->passwordCustomer('correct-horse');

        Livewire::test(CustomerLogin::class)
            ->set('identifier', $user->phone)
            ->set('password', 'correct-horse')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('customer.home'));

        $this->assertAuthenticatedAs($user->fresh());
    }

    // ============================== Customer web Google login ==============================

    /** Puts a server-verified Google identity in the session, the way Login::continueWithGoogle does — mirrors CustomerGoogleAuthTest's own helper. */
    private function stashGoogle(string $email, string $uid): void
    {
        session()->put('auth.google', [
            'uid' => $uid, 'email' => $email, 'name' => 'Google User', 'picture' => null,
            'email_verified' => true, 'verified_at' => now()->timestamp,
        ]);
    }

    public function test_a_suspended_customer_cannot_complete_google_login_via_login_screen(): void
    {
        $user = $this->passwordCustomer('pw', ['status' => 'suspended', 'email' => 'suspended-x@example.test', 'google_id' => 'guid-x', 'firebase_uid' => 'guid-x']);
        $token = $this->firebase->issueGoogleToken($user->email, uid: 'guid-x');

        Livewire::test(CustomerLogin::class)
            ->call('continueWithGoogle', $token)
            ->assertSet('googleError', 'This account has been suspended. Contact support for help.');

        $this->assertFalse(Auth::guard('web')->check());
    }

    /** Regression: an active, already-Google-linked customer signs in via the login screen exactly as before this fix. */
    public function test_an_active_google_linked_customer_still_signs_in_via_login_screen(): void
    {
        $user = $this->passwordCustomer('pw', ['email' => 'active-y@example.test', 'google_id' => 'guid-y', 'firebase_uid' => 'guid-y']);
        $token = $this->firebase->issueGoogleToken($user->email, uid: 'guid-y');

        Livewire::test(CustomerLogin::class)
            ->call('continueWithGoogle', $token)
            ->assertRedirect(route('customer.home'));

        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_a_suspended_customer_is_blocked_at_google_auth_mount_for_an_already_linked_account(): void
    {
        $user = $this->passwordCustomer('pw', ['status' => 'suspended', 'email' => 'suspended-a@example.test', 'google_id' => 'guid-a', 'firebase_uid' => 'guid-a']);
        $this->stashGoogle($user->email, 'guid-a');

        Livewire::test(GoogleAuth::class)
            ->assertSet('error', 'This account has been suspended. Contact support for help.');

        $this->assertFalse(Auth::guard('web')->check());
        $this->assertNull(session('auth.google'), 'The stashed Google identity must be cleared on a refused attempt.');
    }

    /** Regression: an active, already-linked account still completes sign-in at GoogleAuth::mount() exactly as before this fix. */
    public function test_an_active_already_linked_account_still_completes_at_google_auth_mount(): void
    {
        $user = $this->passwordCustomer('pw', ['email' => 'active-b@example.test', 'google_id' => 'guid-b', 'firebase_uid' => 'guid-b']);
        $this->stashGoogle($user->email, 'guid-b');

        Livewire::test(GoogleAuth::class)
            ->assertRedirect(route('customer.home'));

        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_a_suspended_customer_is_blocked_at_google_auth_phone_verification_link_step(): void
    {
        $user = $this->passwordCustomer('their-password', ['status' => 'suspended', 'email' => 'known-suspended@example.test']);
        $this->stashGoogle('known-suspended@example.test', 'guid-link-suspended');

        $c = Livewire::test(GoogleAuth::class)->assertSet('mode', 'link')->assertSet('linkUserId', $user->id);

        $token = $this->firebase->issuePhoneToken($this->e164($user->phone));
        $c->call('phoneTokenReceived', $token)
            ->assertSet('error', 'This account has been suspended. Contact support for help.');

        $this->assertFalse(Auth::guard('web')->check());
        $this->assertNull($user->fresh()->google_id, 'A suspended account must not get linked either.');
    }

    /** Regression: an active account still completes the link-by-phone-verification path exactly as before this fix. */
    public function test_an_active_account_still_completes_google_auth_phone_verification_link_step(): void
    {
        $user = $this->passwordCustomer('their-password', ['email' => 'known-active@example.test']);
        $this->stashGoogle('known-active@example.test', 'guid-link-active');

        $c = Livewire::test(GoogleAuth::class)->assertSet('mode', 'link')->assertSet('linkUserId', $user->id);

        $token = $this->firebase->issuePhoneToken($this->e164($user->phone));
        $c->call('phoneTokenReceived', $token)
            ->assertRedirect(route('customer.home'));

        $this->assertSame('guid-link-active', $user->fresh()->google_id);
        $this->assertAuthenticatedAs($user->fresh());
    }

    // ============================== Provider web password login ==============================

    /** A provider account with a real password, at a chosen users.status. */
    private function makeProviderWithPassword(string $status = 'active'): array
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();

        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Provider User',
            'phone' => '9'.fake()->unique()->numerify('#########'),
            'password' => Hash::make('correct-password'),
            'role' => 'provider',
            'status' => $status,
            'franchise_id' => $franchise->id,
        ]);

        $provider = Provider::create([
            'user_id' => $user->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'provider_type' => 'independent', 'kyc_status' => 'approved', 'is_active' => true, 'is_online' => false,
        ]);

        return compact('user', 'provider');
    }

    public function test_a_suspended_provider_cannot_log_in(): void
    {
        ['user' => $user] = $this->makeProviderWithPassword('suspended');

        Livewire::test(ProviderLogin::class)
            ->set('identifier', $user->phone)
            ->set('password', 'correct-password')
            ->call('login')
            ->assertSet('error', 'This account has been suspended. Contact support for help.');

        $this->assertFalse(Auth::guard('web')->check());
    }

    /** Regression: an untouched (active) provider logs in exactly as before this fix. */
    public function test_an_active_provider_still_logs_in_normally(): void
    {
        ['user' => $user] = $this->makeProviderWithPassword('active');

        Livewire::test(ProviderLogin::class)
            ->set('identifier', $user->phone)
            ->set('password', 'correct-password')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('provider.dashboard'));

        $this->assertAuthenticatedAs($user->fresh());
    }

    /**
     * Provider.is_active is a dispatch-eligibility control, not an account
     * suspension — a provider taken out of dispatch rotation via that flag
     * (unaffected by this fix) must still be able to log in, since
     * suspending an account and suspending dispatch eligibility are
     * deliberately kept separate.
     */
    public function test_an_operationally_inactive_provider_can_still_log_in(): void
    {
        ['user' => $user, 'provider' => $provider] = $this->makeProviderWithPassword('active');
        $provider->update(['is_active' => false]);

        Livewire::test(ProviderLogin::class)
            ->set('identifier', $user->phone)
            ->set('password', 'correct-password')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('provider.dashboard'));

        $this->assertAuthenticatedAs($user->fresh());
    }
}
