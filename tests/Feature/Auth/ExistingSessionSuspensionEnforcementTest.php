<?php

namespace Tests\Feature\Auth;

use App\Livewire\Customers\Show as CustomersShow;
use App\Livewire\Providers\Show as ProvidersShow;
use App\Livewire\Roles\Manage as RolesManage;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * REF 1CF-LAUNCH-004 — closes the existing-session gap LAUNCH-003 left
 * open and explicitly deferred: LAUNCH-003 blocked NEW logins for a
 * suspended account on every surface, but an already-established
 * customer/provider web session or Sanctum API token kept working until
 * it naturally expired. This proves:
 *
 *   - EnsureAccountNotSuspended blocks a suspended customer's/provider's
 *     next request on their existing web session — direct mirror of
 *     EnsureHasAdminAccess's already-proven admin-panel behavior, one
 *     level up
 *   - Customers\Show::toggleSuspended() / Roles\Manage::
 *     toggleUserSuspended() / Providers\Show::toggleAccountSuspended()
 *     each revoke the target's own Sanctum tokens on suspend, and ONLY
 *     the target's — an unrelated user's token is untouched
 *   - reactivating restores web access; a revoked API token is NOT
 *     resurrected (a fresh login is the accepted path back, already
 *     proven by SuspendedAccountCannotLoginTest's regression cases)
 *   - Provider.is_active stays untouched through this whole cycle
 */
class ExistingSessionSuspensionEnforcementTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    private function makeApprovedProvider(): Provider
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();

        return $this->makeProviderIn($franchise, $zone);
    }

    /**
     * Pure test-harness hygiene, nothing to do with the suspension fix
     * itself. Two separate leaks, both closed by the same call:
     *   1. Livewire::actingAs($actor) leaves the `web` guard authenticated
     *      as the ADMIN ACTOR for the rest of the test process.
     *   2. Laravel\Sanctum\Guard is wrapped in a RequestGuard, which
     *      caches its FIRST successful ->user() resolution for the whole
     *      guard-instance lifetime (the rest of the test method) and
     *      never re-invokes Sanctum's token lookup after that — so a test
     *      that checks a token, then revokes it, then checks again would
     *      keep getting the stale cached user back regardless of the
     *      token's real state. Auth::forgetGuards() drops BOTH cached
     *      guard instances ('web' and 'sanctum'), forcing a fresh
     *      resolution — DB state, not a stale PHP object — on the next
     *      request. Call this between performing a suspend/reactivate
     *      action and checking the TARGET user's own session/token.
     */
    private function logoutWebGuard(): void
    {
        $this->app['auth']->forgetGuards();
    }

    // ============================== Customer web session ==============================

    public function test_a_suspended_customers_existing_web_session_is_rejected(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer)->get(route('customer.account'))->assertOk();

        $customer->update(['status' => 'suspended']);

        $this->actingAs($customer)->get(route('customer.account'))->assertForbidden();
    }

    /** Regression: an active customer's session is completely unaffected by this fix existing. */
    public function test_an_active_customers_web_session_is_unaffected(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer)->get(route('customer.account'))->assertOk();
    }

    public function test_reactivating_a_customer_restores_web_access(): void
    {
        $customer = $this->makeCustomer();
        $customer->update(['status' => 'suspended']);

        $this->actingAs($customer)->get(route('customer.account'))->assertForbidden();

        $customer->update(['status' => 'active']);

        $this->actingAs($customer)->get(route('customer.account'))->assertOk();
    }

    // ============================== Provider web session ==============================

    public function test_a_suspended_providers_existing_web_session_is_rejected(): void
    {
        $provider = $this->makeApprovedProvider();

        $this->actingAs($provider->user)->get(route('provider.dashboard'))->assertOk();

        $provider->user->update(['status' => 'suspended']);

        $this->actingAs($provider->user)->get(route('provider.dashboard'))->assertForbidden();
    }

    /** Regression: an active provider's session is completely unaffected by this fix existing. */
    public function test_an_active_providers_web_session_is_unaffected(): void
    {
        $provider = $this->makeApprovedProvider();

        $this->actingAs($provider->user)->get(route('provider.dashboard'))->assertOk();
    }

    public function test_reactivating_a_provider_restores_web_access(): void
    {
        $provider = $this->makeApprovedProvider();
        $provider->user->update(['status' => 'suspended']);

        $this->actingAs($provider->user)->get(route('provider.dashboard'))->assertForbidden();

        $provider->user->update(['status' => 'active']);

        $this->actingAs($provider->user)->get(route('provider.dashboard'))->assertOk();
    }

    // ============================== Sanctum token revocation ==============================

    public function test_suspending_a_customer_revokes_their_existing_sanctum_token(): void
    {
        $customer = $this->makeCustomer();
        $token = $customer->createToken('device')->plainTextToken;

        $this->withToken($token)->getJson('/api/user')->assertOk();
        $this->logoutWebGuard();

        $actor = $this->makeUserWithPermission('customers.view', 'global');
        $this->grantPermission($actor, 'customers.manage', 'global');

        Livewire::actingAs($actor)->test(CustomersShow::class, ['customerId' => $customer->id])
            ->call('toggleSuspended');

        $this->assertSame('suspended', $customer->fresh()->status);
        $this->assertSame(0, $customer->fresh()->tokens()->count());

        $this->logoutWebGuard();
        $this->withToken($token)->getJson('/api/user')->assertStatus(401);
    }

    public function test_suspending_a_customer_does_not_revoke_an_unrelated_customers_token(): void
    {
        $customer = $this->makeCustomer();
        $other = $this->makeCustomer();
        $otherToken = $other->createToken('device')->plainTextToken;

        $actor = $this->makeUserWithPermission('customers.view', 'global');
        $this->grantPermission($actor, 'customers.manage', 'global');

        Livewire::actingAs($actor)->test(CustomersShow::class, ['customerId' => $customer->id])
            ->call('toggleSuspended');

        $this->logoutWebGuard();
        $this->withToken($otherToken)->getJson('/api/user')->assertOk()->assertJsonPath('id', $other->id);
    }

    public function test_suspending_a_provider_revokes_their_existing_sanctum_token_and_leaves_is_active_untouched(): void
    {
        $provider = $this->makeApprovedProvider();
        $token = $provider->user->createToken('device')->plainTextToken;
        $this->assertTrue((bool) $provider->fresh()->is_active);

        $actor = $this->makeUserWithPermission('providers.view', 'global');
        $this->grantPermission($actor, 'providers.manage', 'franchise', $provider->franchise_id);

        Livewire::actingAs($actor)->test(ProvidersShow::class, ['providerId' => $provider->id])
            ->call('toggleAccountSuspended');

        $this->logoutWebGuard();
        $this->withToken($token)->getJson('/api/user')->assertStatus(401);
        $this->assertTrue((bool) $provider->fresh()->is_active, 'is_active (dispatch eligibility) must be untouched by account suspension / token revocation.');
    }

    public function test_reactivating_a_provider_does_not_change_is_active_either(): void
    {
        $provider = $this->makeApprovedProvider();

        $actor = $this->makeUserWithPermission('providers.view', 'global');
        $this->grantPermission($actor, 'providers.manage', 'franchise', $provider->franchise_id);

        Livewire::actingAs($actor)->test(ProvidersShow::class, ['providerId' => $provider->id])
            ->call('toggleAccountSuspended'); // suspend
        Livewire::actingAs($actor)->test(ProvidersShow::class, ['providerId' => $provider->id])
            ->call('toggleAccountSuspended'); // reactivate

        $this->assertSame('active', $provider->user->fresh()->status);
        $this->assertTrue((bool) $provider->fresh()->is_active);
    }

    public function test_suspending_staff_revokes_their_existing_sanctum_token(): void
    {
        $target = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Target Staff',
            'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'operator',
            'status' => 'active',
        ]);
        $token = $target->createToken('device')->plainTextToken;

        $actor = $this->makeUserWithPermission('roles.manage', 'global');

        Livewire::actingAs($actor)->test(RolesManage::class)
            ->set('selectedUserId', $target->id)
            ->call('toggleUserSuspended');

        $this->logoutWebGuard();
        $this->withToken($token)->getJson('/api/user')->assertStatus(401);
    }

    /** Documents the accepted behavior: reactivation restores login ability, not the specific revoked token. */
    public function test_reactivating_a_customer_does_not_resurrect_a_previously_revoked_token(): void
    {
        $customer = $this->makeCustomer();
        $token = $customer->createToken('device')->plainTextToken;

        $actor = $this->makeUserWithPermission('customers.view', 'global');
        $this->grantPermission($actor, 'customers.manage', 'global');

        Livewire::actingAs($actor)->test(CustomersShow::class, ['customerId' => $customer->id])
            ->call('toggleSuspended'); // suspend
        Livewire::actingAs($actor)->test(CustomersShow::class, ['customerId' => $customer->id])
            ->call('toggleSuspended'); // reactivate

        $this->assertSame('active', $customer->fresh()->status);
        $this->logoutWebGuard();
        $this->withToken($token)->getJson('/api/user')->assertStatus(401);
    }
}
