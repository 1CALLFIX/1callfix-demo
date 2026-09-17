<?php

namespace Tests\Feature\Providers;

use App\Livewire\Providers\Show as ProvidersShow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * REF 1CF-LAUNCH-003-IMPLEMENT — Providers\Show::toggleAccountSuspended(),
 * the write side that lets an authorized admin set a provider's own
 * users.status = suspended/active. LAUNCH-002's verification found this
 * had NO admin UI path at all: Provider.is_active (dispatch eligibility)
 * already existed as a concept in the original f4d8d12 fix, but nothing
 * anywhere could suspend a provider's actual login access. This is a
 * distinct, new action — not a port of f4d8d12's Providers\Show::
 * toggleActive(), which this session deliberately does not add (out of
 * scope: a dispatch-eligibility toggle, not an account-suspension one).
 *
 * The critical thing this suite proves, per LAUNCH-003's own instruction
 * to "preserve the separation": toggling account suspension NEVER touches
 * Provider.is_active, and vice versa — they are independent controls on
 * the same screen.
 */
class ProviderAccountSuspensionTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    // ============================== Permission / scope ==============================

    public function test_providers_manage_actor_can_suspend_and_reactivate_a_providers_account(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $actor = $this->makeUserWithPermission('providers.view', 'global');
        $this->grantPermission($actor, 'providers.manage', 'franchise', $franchise->id);

        Livewire::actingAs($actor)->test(ProvidersShow::class, ['providerId' => $provider->id])
            ->call('toggleAccountSuspended')
            ->assertSet('flashType', 'success');

        $this->assertSame('suspended', $provider->user->fresh()->status);

        Livewire::actingAs($actor)->test(ProvidersShow::class, ['providerId' => $provider->id])
            ->call('toggleAccountSuspended')
            ->assertSet('flashType', 'success');

        $this->assertSame('active', $provider->user->fresh()->status);
    }

    public function test_actor_with_only_providers_view_cannot_suspend(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $actor = $this->makeUserWithPermission('providers.view', 'global');

        Livewire::actingAs($actor)->test(ProvidersShow::class, ['providerId' => $provider->id])
            ->call('toggleAccountSuspended')
            ->assertSet('flashType', 'error');

        $this->assertSame('active', $provider->user->fresh()->status);
    }

    public function test_providers_manage_scoped_to_a_different_franchise_cannot_suspend(): void
    {
        [, , $franchiseA, $zoneA] = $this->makeFranchiseTree();
        [, , $franchiseB] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchiseA, $zoneA);
        $actor = $this->makeUserWithPermission('providers.view', 'global');
        $this->grantPermission($actor, 'providers.manage', 'franchise', $franchiseB->id);

        Livewire::actingAs($actor)->test(ProvidersShow::class, ['providerId' => $provider->id])
            ->call('toggleAccountSuspended')
            ->assertSet('flashType', 'error');

        $this->assertSame('active', $provider->user->fresh()->status);
    }

    /** Safety guard mirroring Roles\Manage::toggleUserSuspended()'s identical reasoning. */
    public function test_actor_cannot_suspend_their_own_provider_account(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $this->grantPermission($provider->user, 'providers.manage', 'franchise', $franchise->id);
        $this->grantPermission($provider->user, 'providers.view', 'global');

        Livewire::actingAs($provider->user)->test(ProvidersShow::class, ['providerId' => $provider->id])
            ->call('toggleAccountSuspended')
            ->assertSet('flashType', 'error')
            ->assertSet('flashMessage', 'You cannot suspend your own account.');

        $this->assertSame('active', $provider->user->fresh()->status);
    }

    // ============================== users.status vs Provider.is_active ==============================

    /** The core distinction LAUNCH-003 requires: suspending the account never touches the separate dispatch-eligibility flag. */
    public function test_suspending_the_account_does_not_change_provider_is_active(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $this->assertTrue((bool) $provider->fresh()->is_active);

        $actor = $this->makeUserWithPermission('providers.view', 'global');
        $this->grantPermission($actor, 'providers.manage', 'franchise', $franchise->id);

        Livewire::actingAs($actor)->test(ProvidersShow::class, ['providerId' => $provider->id])
            ->call('toggleAccountSuspended')
            ->assertSet('flashType', 'success');

        $this->assertSame('suspended', $provider->user->fresh()->status);
        $this->assertTrue((bool) $provider->fresh()->is_active, 'is_active (dispatch eligibility) must be untouched by an account suspension.');
    }

    /** Regression: an untouched provider's account status is unaffected by this fix existing. */
    public function test_an_untouched_provider_remains_active(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);

        $this->assertSame('active', $provider->user->fresh()->status);
    }
}
