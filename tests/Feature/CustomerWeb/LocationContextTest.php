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
        $zone->update(['center_lat' => null, 'center_lng' => null, 'boundary_polygon' => null]);

        $this->assertNull($this->context()->nearestCoveringZone(0.0, 0.0));
    }

    // ============== Point-in-polygon coverage (real boundary) ==============
    //
    // A tall, thin N–S rectangle around Nellore's real centre. Its arithmetic
    // centroid is (14.445, 79.985) — the same shape/scale that made the old
    // centroid+radius circle reject users who were plainly inside the drawn
    // zone. NOT the production polygon for zone 41 (that has 7 vertices); a
    // representative shape that reproduces the failure mode.
    private const NELLORE_ISH_POLYGON = [
        ['lat' => 14.30, 'lng' => 79.95],
        ['lat' => 14.59, 'lng' => 79.95],
        ['lat' => 14.59, 'lng' => 80.02],
        ['lat' => 14.30, 'lng' => 80.02],
    ];

    public function test_a_point_inside_the_drawn_boundary_matches_even_when_far_from_the_centroid(): void
    {
        [, , , $zone] = $this->makeFranchiseTree();
        $zone->update([
            'boundary_polygon' => self::NELLORE_ISH_POLYGON,
            'center_lat' => 14.445, 'center_lng' => 79.985,
            'default_dispatch_radius_km' => 10,
        ]);

        // ~12.8 km north of the centroid: inside the rectangle, but well
        // outside the 10 km circle the old check used. This is the exact
        // shape of the production bug.
        $point = [14.56, 79.98];
        $this->assertGreaterThan(
            10,
            app(\App\Services\DispatchService::class)->haversineKm($point[0], $point[1], 14.445, 79.985),
            'guard: the test point must be outside the old circle for this test to mean anything',
        );

        $match = $this->context()->nearestCoveringZone(...$point);

        $this->assertNotNull($match);
        $this->assertSame($zone->id, $match->id);
    }

    public function test_a_point_outside_the_drawn_boundary_is_still_rejected(): void
    {
        [, , , $zone] = $this->makeFranchiseTree();
        $zone->update([
            'boundary_polygon' => self::NELLORE_ISH_POLYGON,
            'center_lat' => 14.445, 'center_lng' => 79.985,
            'default_dispatch_radius_km' => 10,
        ]);

        // North of the rectangle's top edge AND outside the circle.
        $this->assertNull($this->context()->nearestCoveringZone(14.70, 79.98));
    }

    public function test_the_centroid_radius_circle_still_covers_a_zone_with_no_usable_polygon(): void
    {
        [, , , $zone] = $this->makeFranchiseTree();
        $zone->update([
            'boundary_polygon' => null,
            'center_lat' => 14.4426, 'center_lng' => 79.9865,
            'default_dispatch_radius_km' => 8,
        ]);

        // ~1.1 km from the centre — the pass-2 fallback must still catch it.
        $match = $this->context()->nearestCoveringZone(14.4526, 79.9865);

        $this->assertNotNull($match);
        $this->assertSame($zone->id, $match->id);
    }

    public function test_when_the_point_is_inside_two_polygons_the_nearer_centre_wins(): void
    {
        [, , , $a] = $this->makeFranchiseTree();
        [, , , $b] = $this->makeFranchiseTree();

        $a->update([
            'boundary_polygon' => [
                ['lat' => 14.40, 'lng' => 79.95], ['lat' => 14.60, 'lng' => 79.95],
                ['lat' => 14.60, 'lng' => 80.05], ['lat' => 14.40, 'lng' => 80.05],
            ],
            'center_lat' => 14.50, 'center_lng' => 80.00, 'default_dispatch_radius_km' => 1,
        ]);
        $b->update([
            'boundary_polygon' => [
                ['lat' => 14.45, 'lng' => 79.90], ['lat' => 14.65, 'lng' => 79.90],
                ['lat' => 14.65, 'lng' => 80.10], ['lat' => 14.45, 'lng' => 80.10],
            ],
            'center_lat' => 14.55, 'center_lng' => 80.00, 'default_dispatch_radius_km' => 1,
        ]);

        // Inside BOTH rectangles, outside BOTH 1 km circles. Nearer centroid is $a's.
        $match = $this->context()->nearestCoveringZone(14.52, 80.00);

        $this->assertSame($a->id, $match->id);
    }

    /**
     * The production shape this whole change exists for. zones.id=41
     * ("Nellore Main"): the real 7-vertex boundary_polygon and centre pulled
     * from production, radius 10 km. A point interpolated just inside the
     * south-west vertex is ~10.9 km from the centre — inside the drawn zone,
     * but outside the 10 km circle the old check used, so the old code told
     * the customer "not serving your area" with Nellore Main listed below.
     */
    public function test_zone_41_real_boundary_matches_a_point_beyond_its_circle_radius(): void
    {
        $centreLat = 14.4452399;
        $centreLng = 79.9831226;

        [, , , $zone] = $this->makeFranchiseTree();
        $zone->update([
            'name' => 'Nellore Main',
            'boundary_polygon' => self::ZONE_41_BOUNDARY,
            'center_lat' => $centreLat,
            'center_lng' => $centreLng,
            'default_dispatch_radius_km' => 10,
        ]);

        $haversine = fn (float $aLat, float $aLng, float $bLat, float $bLng): float => app(\App\Services\DispatchService::class)
            ->haversineKm($aLat, $aLng, $bLat, $bLng);

        // Vertex 5 (south-west) is itself already outside the 10 km circle.
        $vertex = self::ZONE_41_BOUNDARY[5];
        $this->assertGreaterThan(
            10,
            $haversine($vertex['lat'], $vertex['lng'], $centreLat, $centreLng),
            'guard: vertex 5 is expected to sit outside the old circle',
        );

        // Step 7 % in from that vertex toward the centre so the point is
        // strictly interior, not sitting on the boundary edge.
        $t = 0.07;
        $pointLat = $vertex['lat'] + $t * ($centreLat - $vertex['lat']);
        $pointLng = $vertex['lng'] + $t * ($centreLng - $vertex['lng']);

        // Still outside the 10 km circle — the old centroid+radius check would
        // have rejected this point.
        $this->assertGreaterThan(
            10,
            $haversine($pointLat, $pointLng, $centreLat, $centreLng),
            'the interior test point must be beyond the circle radius for this test to mean anything',
        );

        // It is inside the real drawn boundary...
        $pointInPolygon = new \ReflectionMethod(CustomerLocationContext::class, 'pointInPolygon');
        $pointInPolygon->setAccessible(true);
        $this->assertTrue(
            $pointInPolygon->invoke($this->context(), $pointLat, $pointLng, self::ZONE_41_BOUNDARY),
        );

        // ...so the resolver now returns zone 41, where the circle check could not.
        $match = $this->context()->nearestCoveringZone($pointLat, $pointLng);
        $this->assertNotNull($match);
        $this->assertSame($zone->id, $match->id);
    }

    // zones.id=41 "Nellore Main" — the exact boundary_polygon stored in production.
    private const ZONE_41_BOUNDARY = [
        ['lat' => 14.517477179743869, 'lng' => 79.98150405599405],
        ['lat' => 14.517809540371763, 'lng' => 79.9842506380253],
        ['lat' => 14.489224702751635, 'lng' => 80.05806503011515],
        ['lat' => 14.426724091994725, 'lng' => 80.03403243734171],
        ['lat' => 14.370857636576341, 'lng' => 79.98631057454874],
        ['lat' => 14.359549629986939, 'lng' => 79.92039260579874],
        ['lat' => 14.435036356310146, 'lng' => 79.91730270101358],
    ];

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

    // ---------- Automatic first-load geolocation (Phase 2) ----------

    public function test_auto_geolocation_inside_coverage_selects_the_zone_without_opening_the_picker(): void
    {
        [, , , $zone] = $this->makeFranchiseTree();
        $zone->update(['center_lat' => 14.4426, 'center_lng' => 79.9865, 'default_dispatch_radius_km' => 8]);

        Livewire::test(LocationPicker::class)
            ->call('useCurrentLocationAuto', 14.4426, 79.9865)
            ->assertSet('open', false)
            ->assertSet('outOfCoverage', false)
            ->assertDispatched('customer-zone-changed');

        $this->assertSame($zone->id, session(CustomerLocationContext::SESSION_KEY));
    }

    public function test_auto_geolocation_outside_coverage_opens_the_picker_on_the_notice(): void
    {
        [, , , $zone] = $this->makeFranchiseTree();
        $zone->update(['center_lat' => 14.4426, 'center_lng' => 79.9865, 'default_dispatch_radius_km' => 8]);

        Livewire::test(LocationPicker::class)
            ->call('useCurrentLocationAuto', 51.5072, -0.1276)
            ->assertSet('open', true)
            ->assertSet('outOfCoverage', true)
            ->assertSeeText('not serving your current location yet');

        $this->assertNull(session(CustomerLocationContext::SESSION_KEY));
    }

    public function test_auto_geolocation_rejects_out_of_range_coordinates(): void
    {
        Livewire::test(LocationPicker::class)
            ->call('useCurrentLocationAuto', 999.0, 999.0)
            ->assertHasErrors();

        $this->assertNull(session(CustomerLocationContext::SESSION_KEY));
    }

    // ---------- Accuracy-aware "not serving your area" copy ----------

    public function test_a_coarse_auto_fix_opens_the_picker_but_suppresses_the_not_serving_copy(): void
    {
        [, , , $zone] = $this->makeFranchiseTree();
        $zone->update(['center_lat' => 14.4426, 'center_lng' => 79.9865, 'default_dispatch_radius_km' => 8]);

        // Far from the zone, but the browser only knows the position to ~30 km.
        Livewire::test(LocationPicker::class)
            ->call('useCurrentLocationAuto', 51.5072, -0.1276, 30000.0)
            ->assertSet('open', true)
            ->assertSet('outOfCoverage', false)
            ->assertDontSeeText('not serving your current location yet');

        $this->assertNull(session(CustomerLocationContext::SESSION_KEY));
    }

    public function test_a_precise_auto_fix_outside_coverage_still_shows_the_not_serving_copy(): void
    {
        [, , , $zone] = $this->makeFranchiseTree();
        $zone->update(['center_lat' => 14.4426, 'center_lng' => 79.9865, 'default_dispatch_radius_km' => 8]);

        // Same point, but this fix is good to 40 m — a confident rejection is fair.
        Livewire::test(LocationPicker::class)
            ->call('useCurrentLocationAuto', 51.5072, -0.1276, 40.0)
            ->assertSet('open', true)
            ->assertSet('outOfCoverage', true)
            ->assertSeeText('not serving your current location yet');
    }

    public function test_a_coarse_manual_fix_does_not_set_out_of_coverage(): void
    {
        [, , , $zone] = $this->makeFranchiseTree();
        $zone->update(['center_lat' => 14.4426, 'center_lng' => 79.9865, 'default_dispatch_radius_km' => 8]);

        Livewire::test(LocationPicker::class)
            ->call('openPicker')
            ->call('useCurrentLocation', 51.5072, -0.1276, 25000.0)
            ->assertSet('outOfCoverage', false)
            ->assertDontSeeText('not serving your current location yet');
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
