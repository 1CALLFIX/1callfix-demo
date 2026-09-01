<?php

namespace Tests\Feature\ProviderWeb;

use App\Livewire\Provider\Auth\Login;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * PHASE PW1 §2 — the shared-session model: one `web` guard, `/provider/*`
 * gated only by "has a providers row", a partner login that refuses a
 * non-partner and keeps the legacy password-migration fork.
 */
class ProviderWebAuthTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    private function providerUserWithPassword(string $password = 'secret-pw-1234'): User
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $provider->user->update(['password' => Hash::make($password)]);

        return $provider->user->fresh();
    }

    private function customerWithPassword(string $password = 'secret-pw-1234'): User
    {
        return User::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Cust', 'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'customer', 'status' => 'active', 'password' => Hash::make($password),
        ]);
    }

    public function test_guest_hitting_the_provider_area_is_redirected_to_provider_login(): void
    {
        $this->get('/provider')->assertRedirect(route('provider.login'));
    }

    public function test_a_customer_is_403ed_from_the_provider_area(): void
    {
        $this->actingAs($this->customerWithPassword())->get('/provider')->assertForbidden();
    }

    public function test_a_provider_reaches_the_dashboard(): void
    {
        $this->actingAs($this->providerUserWithPassword())
            ->get('/provider')
            ->assertOk()
            ->assertSee('offline');
    }

    public function test_provider_login_refuses_a_customer_account_and_starts_no_session(): void
    {
        $customer = $this->customerWithPassword('right-password-9');

        Livewire::test(Login::class)
            ->set('identifier', $customer->phone)
            ->set('password', 'right-password-9')
            ->call('login')
            ->assertSet('error', 'That account is not a registered service partner.');

        $this->assertFalse(Auth::guard('web')->check());
    }

    public function test_provider_login_authenticates_a_partner_and_redirects_to_the_dashboard(): void
    {
        $user = $this->providerUserWithPassword('right-password-9');

        Livewire::test(Login::class)
            ->set('identifier', $user->phone)
            ->set('password', 'right-password-9')
            ->call('login')
            ->assertRedirect(route('provider.dashboard'));

        $this->assertTrue(Auth::guard('web')->check());
        $this->assertSame($user->id, Auth::guard('web')->id());
    }

    public function test_wrong_password_is_rejected(): void
    {
        $user = $this->providerUserWithPassword('right-password-9');

        Livewire::test(Login::class)
            ->set('identifier', $user->phone)
            ->set('password', 'wrong')
            ->call('login')
            ->assertSet('error', 'Those details do not match an account.');

        $this->assertFalse(Auth::guard('web')->check());
    }

    public function test_a_password_less_provider_is_sent_to_the_one_time_migration_flow(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone); // user has no password

        Livewire::test(Login::class)
            ->set('identifier', $provider->user->phone)
            ->set('password', 'anything')
            ->call('login')
            ->assertRedirect(route('customer.auth.migrate', ['identifier' => $provider->user->phone]));
    }

    public function test_shared_session_a_provider_can_still_open_the_customer_order_history(): void
    {
        $this->actingAs($this->providerUserWithPassword())
            ->get(route('customer.orders.index'))
            ->assertOk();
    }

    public function test_logout_clears_the_session(): void
    {
        $user = $this->providerUserWithPassword();

        $this->actingAs($user)->post('/provider/logout')->assertRedirect(route('provider.login'));
        $this->assertFalse(Auth::guard('web')->check());
    }
}
