<?php

namespace Tests\Feature\Providers;

use App\Livewire\Providers\Show as ProvidersShow;
use App\Models\Address;
use App\Models\Booking;
use App\Services\DispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Enable/Disable Audit gap fix — Providers\Show::toggleActive(). Providers
 * had a KYC approve/reject workflow and a (soft) delete, but no reversible
 * way to take an approved provider temporarily out of dispatch. Unlike
 * Customers\Show::toggleSuspended() (which writes a column nothing
 * downstream reads), this writes Provider.is_active, which
 * DispatchService::providerEligibleForBooking() already gates on — so the
 * key thing this suite proves is not just "the flag flipped" but that a
 * suspended provider genuinely stops being eligible for a real booking
 * through the real production eligibility check, and a reactivated one
 * becomes eligible again.
 */
class ProvidersShowSuspendTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    /** A provider positioned + skilled + online + approved so providerEligibleForBooking() is true, plus a real booking they're eligible for. */
    private function makeEligibleProviderAndBooking(): array
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        [$category, $service] = $this->makeCategoryAndService();
        $customer = $this->makeCustomer();

        $address = Address::create([
            'user_id' => $customer->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'label' => 'Home', 'lat' => 1.0005, 'lng' => 1.0005, 'address_line' => 'Addr',
        ]);

        $provider = $this->makeProviderIn($franchise, $zone);
        $provider->update([
            'skills' => [$category->id],
            'current_lat' => 1.0,
            'current_lng' => 1.0,
        ]);

        $booking = Booking::create([
            'code' => 'TST-'.now()->format('dm').'-'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'customer_id' => $customer->id, 'service_id' => $service->id, 'address_id' => $address->id,
            'status' => 'searching_provider', 'price_quoted' => 500, 'payment_status' => 'pending', 'payment_method' => 'online',
        ]);

        return compact('franchise', 'zone', 'provider', 'booking');
    }

    // ============================== Permission / scope ==============================

    public function test_providers_manage_actor_can_suspend_and_reactivate(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $actor = $this->makeUserWithPermission('providers.view', 'global');
        $this->grantPermission($actor, 'providers.manage', 'franchise', $franchise->id);

        Livewire::actingAs($actor)->test(ProvidersShow::class, ['providerId' => $provider->id])
            ->call('toggleActive')
            ->assertSet('flashType', 'success');

        $this->assertFalse((bool) $provider->fresh()->is_active);

        Livewire::actingAs($actor)->test(ProvidersShow::class, ['providerId' => $provider->id])
            ->call('toggleActive')
            ->assertSet('flashType', 'success');

        $this->assertTrue((bool) $provider->fresh()->is_active);
    }

    public function test_actor_with_only_providers_view_cannot_toggle(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $actor = $this->makeUserWithPermission('providers.view', 'global');

        Livewire::actingAs($actor)->test(ProvidersShow::class, ['providerId' => $provider->id])
            ->call('toggleActive')
            ->assertSet('flashType', 'error');

        $this->assertTrue((bool) $provider->fresh()->is_active, 'Default is_active must be untouched by a refused toggle.');
    }

    public function test_providers_manage_scoped_to_a_different_franchise_cannot_toggle(): void
    {
        [, , $franchiseA, $zoneA] = $this->makeFranchiseTree();
        [, , $franchiseB] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchiseA, $zoneA);
        $actor = $this->makeUserWithPermission('providers.view', 'global');
        $this->grantPermission($actor, 'providers.manage', 'franchise', $franchiseB->id);

        Livewire::actingAs($actor)->test(ProvidersShow::class, ['providerId' => $provider->id])
            ->call('toggleActive')
            ->assertSet('flashType', 'error');

        $this->assertTrue((bool) $provider->fresh()->is_active);
    }

    // ============================== Real dispatch-eligibility proof ==============================

    /**
     * The actual point of this whole fix: not "the flag flipped in the
     * database" but "a suspended provider genuinely cannot be offered this
     * job anymore", proven through DispatchService's own real eligibility
     * gate — the exact method findCandidates() itself relies on for every
     * real dispatch round (see its own docblock).
     */
    public function test_suspending_a_provider_makes_them_ineligible_for_a_real_booking_via_dispatch_service(): void
    {
        ['franchise' => $franchise, 'provider' => $provider, 'booking' => $booking] = $this->makeEligibleProviderAndBooking();
        $actor = $this->makeUserWithPermission('providers.view', 'global');
        $this->grantPermission($actor, 'providers.manage', 'franchise', $franchise->id);

        $dispatch = app(DispatchService::class);

        // Sanity check: genuinely eligible BEFORE any suspension, so the
        // "now ineligible" assertion below proves the suspension caused it,
        // not some unrelated fixture mistake.
        $this->assertTrue(
            $dispatch->providerEligibleForBooking($provider->fresh(), $booking),
            'Fixture must be eligible before suspension for this test to prove anything.'
        );

        Livewire::actingAs($actor)->test(ProvidersShow::class, ['providerId' => $provider->id])
            ->call('toggleActive')
            ->assertSet('flashType', 'success');

        $this->assertFalse(
            $dispatch->providerEligibleForBooking($provider->fresh(), $booking),
            'A suspended provider must not be eligible for new job offers.'
        );

        // And reactivating restores real eligibility, not just the raw flag.
        Livewire::actingAs($actor)->test(ProvidersShow::class, ['providerId' => $provider->id])
            ->call('toggleActive')
            ->assertSet('flashType', 'success');

        $this->assertTrue($dispatch->providerEligibleForBooking($provider->fresh(), $booking));
    }

    /** Regression: an untouched, still-active provider's real eligibility is unaffected by this fix existing. */
    public function test_an_untouched_active_provider_remains_eligible(): void
    {
        ['provider' => $provider, 'booking' => $booking] = $this->makeEligibleProviderAndBooking();

        $this->assertTrue(app(DispatchService::class)->providerEligibleForBooking($provider->fresh(), $booking));
    }
}
