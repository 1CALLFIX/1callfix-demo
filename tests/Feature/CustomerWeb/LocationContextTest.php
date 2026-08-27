<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Customer\LocationPicker;
use App\Models\Zone;
use App\Services\Customer\CustomerLocationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Customer location/zone context (Phase B).
 *
 * The security-relevant assertions here are the ones proving the franchise
 * is always DERIVED from the chosen zone and never taken from anything the
 * client can set — the same rule AddressController::store() enforces — and
 * that an inactive zone can never become the active context.
 */
class LocationContextTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;

    private function context(): CustomerLocationContext
    {
        return app(CustomerLocationContext::class);
    }

    // ==================== Session context ====================

    public function test_no_zone_is_selected_by_default(): void
    {
        $this->assertNull($this->context()->zone());
        $this->assertNull($this->context()->franchiseId());
    }

    public function test_selecting_a_zone_derives_the_franchise_from_that_zone(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();

        $this->assertTrue($this->context()->setZone($zone->id));
        $this->assertSame($zone->id, $this->context()->zone()->id);
        $this->assertSame($franchise->id, $this->context()->franchiseId());
    }

    public function test_an_inactive_zone_cannot_be_selected(): void
    {
        [, , , $zone] = $this->makeFranchiseTree();
        $zone->update(['is_active' => false]);

        $this->assertFalse($this->context()->setZone($zone->id));
        $this->assertNull($this->context()->zone());
    }

    public function test_an_unknown_zone_id_cannot_be_selected(): void
    {
        $this->assertFalse($this->context()->setZone(999999));
        $this->assertNull($this->context()->zone());
    }

    /**
     * A zone deactivated AFTER it was chosen must stop resolving, rather
     * than continuing to scope pricing from a stale session value.
     */
    public function test_a_zone_deactivated_after_selection_stops_resolving(): void
    {
        [, , , $zone] = $this->makeFranchiseTree();
        $this->context()->setZone($zone->id);

        $zone->update(['is_active' => false]);

        $this->assertNull($this->context()->zone());
        $this->assertNull($this->context()->franchiseId());
    }

    public function test_clearing_removes_the_context(): void
    {
        [, , , $zone] = $this->makeFranchiseTree();
        $this->context()->setZone($zone->id);

        $this->context()->clear();

        $this->assertNull($this->context()->zone());
    }

    // ==================== Nearest-covering-zone lookup ====================

    public function test_a_point_inside_a_zones_own_dispatch_radius_matches_that_zone(): void
    {
        [, , , $zone] = $this->makeFranchiseTree();
        $zone->update(['center_lat' => 14.4426, 'center_lng' => 79.9865, 'default_dispatch_radius_km' => 8]);

        // ~1.1 km north of the centre — comfortably inside the 8 km radius.
        $match = $this->context()->nearestCoveringZone(14.4526, 79.9865);

        $this->assertNotNull($match);
        $this->assertSame($zone->id, $match->id);
    }

    /**
     * The honest-answer case. A point no zone's radius reaches must return
     * null, NOT the least-far zone — snapping would silently tell a
     * customer they are covered when they are not.
     */
    public function test_a_point_outside_every_radius_matches_nothing(): void
    {
        [, , , $zone] = $this->makeFranchiseTree();
        $zone->update(['center_lat' => 14.4426, 'center_lng' => 79.9865, 'default_dispatch_radius_km' => 8]);

        // London — thousands of km away.
        $this->assertNull($this->context()->nearestCoveringZone(51.5072, -0.1276));
    }

    public function test_the_nearest_of_several_covering_zones_wins(): void
    {
        [, , , $near] = $this->makeFranchiseTree();
        [, , , $far] = $this->makeFranchiseTree();

        $near->update(['center_lat' => 14.4426, 'center_lng' => 79.9865, 'default_dispatch_radius_km' => 20]);
        $far->update(['center_lat' => 14.5426, 'center_lng' => 79.9865, 'default_dispatch_radius_km' => 20]);

        $match = $this->context()->nearestCoveringZone(14.4430, 79.9865);

        $this->assertSame($near->id, $match->id);
    }

    public function test_inactive_zones_are_never_matched_by_coordinates(): void
    {
        [, , , $zone] = $this->makeFranchiseTree();
        $zone->update([
            'center_lat' => 14.4426, 'center_lng' => 79.9865,
            'default_dispatch_radius_km' => 8, 'is_active' => false,
        ]);

        $this->assertNull($this->context()->nearestCoveringZone(14.4426, 79.9865));
    }

    /**
     * A zone with no centre recorded cannot be measured against. Treating a
     * missing centre as (0,0) would place every such zone in the Gulf of
     * Guinea and make it "match" coordinates near the equator.
     */
    public function test_zones_without_a_centre_are_skipped(): void
    {
        [, , , $zone] = $this->makeFranchiseTree();
        $zone->update(['center_lat' => null, 'center_lng' => null]);

        $this->assertNull($this->context()->nearestCoveringZone(0.0, 0.0));
    }

    // ==================== Livewire component ====================

    public function test_the_picker_lists_only_active_zones(): void
    {
        [, , , $active] = $this->makeFranchiseTree();
        [, , , $inactive] = $this->makeFranchiseTree();
        $active->update(['name' => 'Visible Zone']);
        $inactive->update(['name' => 'Hidden Zone', 'is_active' => false]);

        Livewire::test(LocationPicker::class)
            ->call('openPicker')
            ->assertSeeText('Visible Zone')
            ->assertDontSeeText('Hidden Zone');
    }

    public function test_selecting_a_zone_from_the_picker_stores_it_and_closes(): void
    {
        [, , , $zone] = $this->makeFranchiseTree();

        Livewire::test(LocationPicker::class)
            ->call('openPicker')
            ->call('selectZone', $zone->id)
            ->assertSet('open', false)
            ->assertDispatched('customer-zone-changed');

        $this->assertSame($zone->id, session(CustomerLocationContext::SESSION_KEY));
    }

    public function test_the_picker_refuses_an_inactive_zone_id(): void
    {
        [, , , $zone] = $this->makeFranchiseTree();
        $zone->update(['is_active' => false]);

        Livewire::test(LocationPicker::class)
            ->call('openPicker')
            ->call('selectZone', $zone->id)
            ->assertSet('open', true);

        $this->assertNull(session(CustomerLocationContext::SESSION_KEY));
    }

    public function test_geolocation_outside_coverage_reports_it_honestly(): void
    {
        [, , , $zone] = $this->makeFranchiseTree();
        $zone->update(['center_lat' => 14.4426, 'center_lng' => 79.9865, 'default_dispatch_radius_km' => 8]);

        Livewire::test(LocationPicker::class)
            ->call('openPicker')
            ->call('useCurrentLocation', 51.5072, -0.1276)
            ->assertSet('outOfCoverage', true)
            ->assertSeeText('not serving your current location yet');

        $this->assertNull(session(CustomerLocationContext::SESSION_KEY));
    }

    public function test_geolocation_inside_coverage_selects_the_zone(): void
    {
        [, , , $zone] = $this->makeFranchiseTree();
        $zone->update(['center_lat' => 14.4426, 'center_lng' => 79.9865, 'default_dispatch_radius_km' => 8]);

        Livewire::test(LocationPicker::class)
            ->call('openPicker')
            ->call('useCurrentLocation', 14.4426, 79.9865)
            ->assertSet('open', false);

        $this->assertSame($zone->id, session(CustomerLocationContext::SESSION_KEY));
    }

    public function test_out_of_range_coordinates_are_rejected(): void
    {
        Livewire::test(LocationPicker::class)
            ->call('openPicker')
            ->call('useCurrentLocation', 999.0, 999.0)
            ->assertHasErrors();
    }

    public function test_searching_narrows_the_zone_list(): void
    {
        [, , , $alpha] = $this->makeFranchiseTree();
        [, , , $beta] = $this->makeFranchiseTree();
        $alpha->update(['name' => 'Alphaville']);
        $beta->update(['name' => 'Betatown']);

        Livewire::test(LocationPicker::class)
            ->call('openPicker')
            ->set('search', 'Alpha')
            ->assertSeeText('Alphaville')
            ->assertDontSeeText('Betatown');
    }

    // ==================== Pricing context ====================

    /**
     * Prices on the homepage must come from Service::resolvePrice() with the
     * franchise derived from the session zone — never recomputed in the view
     * and never taken from the client.
     */
    public function test_the_homepage_price_reflects_the_franchise_pricing_override_for_the_active_zone(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        [, $service] = $this->makeCategoryAndService();

        \App\Models\FranchiseServicePricing::create([
            'franchise_id' => $franchise->id,
            'service_id' => $service->id,
            'is_offered' => true,
            'price_override' => 1234.00,
        ]);

        // Without a zone context the base price is shown...
        $this->get(route('customer.home'))->assertOk()->assertSeeText('500.00');

        // ...and with it, the franchise override the server resolved.
        $this->withSession([CustomerLocationContext::SESSION_KEY => $zone->id])
            ->get(route('customer.home'))
            ->assertOk()
            ->assertSeeText('1,234.00');
    }
}
