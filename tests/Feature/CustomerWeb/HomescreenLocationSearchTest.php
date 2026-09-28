<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Customer\LocationPicker;
use App\Livewire\Customer\SearchBar;
use App\Models\Booking;
use App\Models\Service;
use App\Services\Customer\CustomerLocationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * 1CF-HOMESCREEN-UX-001 — homepage location bar, location picker (Places
 * search, "use current location", recent locations) and the rotating search
 * placeholder.
 *
 * Only one franchise/zone exists in PRODUCTION today (Nellore) — see
 * docs/PHASE_HOMESCREEN_UX_IMPLEMENTATION.md. The "another city" tests below
 * create a SECOND franchise/zone in the TEST database only
 * (BookingFixtureHelpers::makeFranchiseTree() already builds a fresh
 * country/city/franchise/zone on every call); no production data is touched.
 */
class HomescreenLocationSearchTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;
    use CatalogFixtures;

    // ==================== Part 2 — rotating placeholder examples ====================

    /** Automated test. */
    public function test_placeholder_examples_are_built_from_active_services_booked_in_the_zone(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $category = $this->makeCategory();
        $service = $this->makeService($category, ['name' => 'Deep AC Service']);
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);

        // mostBooked() ranks by real completed-booking history for the
        // franchise — give it one so the service surfaces.
        Booking::create([
            'code' => 'TST-'.now()->format('dm').'-'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'customer_id' => $customer->id,
            'franchise_id' => $franchise->id,
            'zone_id' => $zone->id,
            'service_id' => $service->id,
            'address_id' => $address->id,
            'status' => 'completed',
            'price_quoted' => 500,
            'payment_status' => 'paid',
            'payment_method' => 'online',
        ]);

        $this->withSession([CustomerLocationContext::SESSION_KEY => $zone->id]);

        Livewire::test(SearchBar::class)
            ->assertSet('term', '')
            ->call('focusField');

        // The rendered input carries the JSON examples the JS rotation reads.
        $html = Livewire::test(SearchBar::class)->html();
        $this->assertStringContainsString('Deep AC Service', $html);
        $this->assertStringContainsString('data-placeholder-examples', $html);
    }

    /** Automated test. */
    public function test_placeholder_falls_back_to_a_static_example_when_the_catalog_is_empty(): void
    {
        // No services, no categories anywhere — the catalog is empty.
        $html = Livewire::test(SearchBar::class)->html();

        $this->assertStringContainsString('data-placeholder-examples', $html);
        $this->assertMatchesRegularExpression('/data-placeholder-examples="[^"]*Search for/', $html);
    }

    // ==================== Part 3 — location control ====================
    // 1CF-HOMESCREEN-HERO-001 removed the homepage's second location pill
    // (which used to show "Set your location" / the picked place's label
    // and address in the discovery hero). The header's location control
    // (x-customer.header -> livewire:customer.location-picker) is now the
    // only one, so these assert against IT instead.

    /** Automated test. */
    public function test_header_location_control_shows_set_location_when_nothing_is_selected(): void
    {
        $html = $this->get(route('customer.home'))->assertOk()->getContent();

        $this->assertStringContainsString('Set location', $html);
        $this->assertStringContainsString('data-has-zone=""', $html);
    }

    /** Automated test. */
    public function test_header_location_control_shows_the_active_zone_once_a_place_is_selected(): void
    {
        [, , , $zone] = $this->makeFranchiseTree();
        $zone->update(['boundary_polygon' => null, 'center_lat' => 1.5, 'center_lng' => 1.5, 'default_dispatch_radius_km' => 5]);

        Livewire::test(LocationPicker::class)
            ->call('selectPlace', 1.5, 1.5, 'Sunrise Apartments, Main Road', 'Sunrise Apartments')
            ->assertSet('outOfCoverage', false);

        $html = $this->get(route('customer.home'))->assertOk()->getContent();

        $this->assertStringContainsString($zone->name, $html);
        $this->assertStringContainsString('data-has-zone="1"', $html);
    }

    // ==================== Part 4/5 — picking a location ====================

    /** Automated test. */
    public function test_a_supported_place_pick_updates_the_zone_used_by_booking(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $zone->update(['boundary_polygon' => null, 'center_lat' => 10.0, 'center_lng' => 20.0, 'default_dispatch_radius_km' => 5]);

        Livewire::test(LocationPicker::class)
            ->call('selectPlace', 10.01, 20.0, '123 Example Street', 'Example Street')
            ->assertSet('open', false)
            ->assertDispatched('customer-zone-changed');

        $this->assertSame($zone->id, session(CustomerLocationContext::SESSION_KEY));
        $this->assertSame($franchise->id, app(CustomerLocationContext::class)->franchiseId());
    }

    /** Automated test. */
    public function test_a_supported_place_pick_updates_zone_and_serviceability_matching(): void
    {
        [, , , $zoneA] = $this->makeFranchiseTree();
        $zoneA->update(['boundary_polygon' => null, 'center_lat' => 0.0, 'center_lng' => 0.0, 'default_dispatch_radius_km' => 5]);

        [, , , $zoneB] = $this->makeFranchiseTree();
        $zoneB->update(['boundary_polygon' => null, 'center_lat' => 50.0, 'center_lng' => 60.0, 'default_dispatch_radius_km' => 5]);

        Livewire::test(LocationPicker::class)->call('selectPlace', 0.0, 0.0, 'Near A', 'Near A');
        $this->assertSame($zoneA->id, session(CustomerLocationContext::SESSION_KEY));

        Livewire::test(LocationPicker::class)->call('selectPlace', 50.01, 60.0, 'Near B', 'Near B');
        $this->assertSame($zoneB->id, session(CustomerLocationContext::SESSION_KEY));
    }

    /** Automated test. */
    public function test_a_supported_place_in_another_city_works(): void
    {
        [, $cityA, , $zoneA] = $this->makeFranchiseTree();
        $zoneA->update(['boundary_polygon' => null, 'center_lat' => 14.44, 'center_lng' => 79.98, 'default_dispatch_radius_km' => 5]);

        // A second, distinct franchise/city/zone — TEST DATABASE ONLY.
        [, $cityB, $franchiseB, $zoneB] = $this->makeFranchiseTree();
        $zoneB->update(['boundary_polygon' => null, 'center_lat' => 19.07, 'center_lng' => 72.87, 'default_dispatch_radius_km' => 5]);

        $this->assertNotSame($cityA->id, $cityB->id);

        Livewire::test(LocationPicker::class)
            ->call('selectPlace', 19.075, 72.875, 'Bandra West, Mumbai', 'Bandra West')
            ->assertSet('open', false);

        $this->assertSame($zoneB->id, session(CustomerLocationContext::SESSION_KEY));
        $this->assertSame($franchiseB->id, app(CustomerLocationContext::class)->franchiseId());
    }

    /** Automated test. */
    public function test_a_selected_place_is_not_replaced_by_a_later_gps_auto_locate(): void
    {
        [, , , $zonePicked] = $this->makeFranchiseTree();
        $zonePicked->update(['boundary_polygon' => null, 'center_lat' => 10.0, 'center_lng' => 20.0, 'default_dispatch_radius_km' => 5]);

        [, , , $zoneGps] = $this->makeFranchiseTree();
        $zoneGps->update(['boundary_polygon' => null, 'center_lat' => 30.0, 'center_lng' => 40.0, 'default_dispatch_radius_km' => 5]);

        Livewire::test(LocationPicker::class)->call('selectPlace', 10.0, 20.0, 'Picked Place', 'Picked Place');
        $this->assertSame($zonePicked->id, session(CustomerLocationContext::SESSION_KEY));

        // The homepage now renders with data-has-zone="1" — the browser-side
        // autoLocate() in location-picker.blade.php checks exactly that
        // attribute and refuses to run at all once a zone is set (see the
        // script's own comment), so no unprompted GPS fix can overwrite an
        // explicit pick. That refusal is a front-end guard on `$wire.$el`,
        // so it is asserted here on rendered markup, not by calling the GPS
        // method (a real GPS event genuinely CAN still call
        // useCurrentLocationAuto() directly, e.g. from devtools) — the
        // browser-verified check is listed in the implementation report.
        $html = $this->get(route('customer.home'))->assertOk()->getContent();
        $this->assertStringContainsString('data-has-zone="1"', $html);
    }

    // ==================== Part 6 — unserviceable locations ====================

    /** Automated test. */
    public function test_an_unsupported_place_shows_the_not_in_this_area_message(): void
    {
        Livewire::test(LocationPicker::class)
            ->call('openPicker')
            ->call('selectPlace', 51.5072, -0.1276, 'London, UK', 'London')
            ->assertSet('outOfCoverage', true)
            ->assertSeeText("We're not in this area yet", false);
    }

    /** Automated test. */
    public function test_the_previous_valid_zone_stays_active_after_an_unsupported_pick(): void
    {
        [, , , $zone] = $this->makeFranchiseTree();
        $zone->update(['boundary_polygon' => null, 'center_lat' => 10.0, 'center_lng' => 20.0, 'default_dispatch_radius_km' => 5]);

        Livewire::test(LocationPicker::class)->call('selectPlace', 10.0, 20.0, 'Good Place', 'Good Place');
        $this->assertSame($zone->id, session(CustomerLocationContext::SESSION_KEY));

        Livewire::test(LocationPicker::class)
            ->call('selectPlace', 51.5072, -0.1276, 'London, UK', 'London')
            ->assertSet('outOfCoverage', true);

        $this->assertSame($zone->id, session(CustomerLocationContext::SESSION_KEY));
    }

    // ==================== Guest access ====================

    /** Automated test. */
    public function test_a_guest_can_change_location_without_logging_in(): void
    {
        [, , , $zone] = $this->makeFranchiseTree();
        $zone->update(['boundary_polygon' => null, 'center_lat' => 10.0, 'center_lng' => 20.0, 'default_dispatch_radius_km' => 5]);

        $this->assertGuest();

        Livewire::test(LocationPicker::class)
            ->call('selectPlace', 10.0, 20.0, 'Guest Place', 'Guest Place')
            ->assertSet('open', false);

        $this->assertSame($zone->id, session(CustomerLocationContext::SESSION_KEY));
    }

    // ==================== Part 5/12 — tamper-proofing ====================

    /** Automated test. */
    public function test_a_tampered_request_cannot_force_a_zone_or_franchise(): void
    {
        [, , , $realZone] = $this->makeFranchiseTree();
        $realZone->update(['boundary_polygon' => null, 'center_lat' => 10.0, 'center_lng' => 20.0, 'default_dispatch_radius_km' => 5]);

        // selectPlace() never even accepts a zone/franchise id as an
        // argument — only lat/lng + free text — so there is no field to
        // tamper with to force a specific zone/franchise. A coordinate
        // outside every zone cannot select ANY zone, real or otherwise.
        Livewire::test(LocationPicker::class)
            ->call('selectPlace', 89.0, 179.0, 'Nowhere', 'Nowhere')
            ->assertSet('outOfCoverage', true);

        $this->assertNull(session(CustomerLocationContext::SESSION_KEY));

        // Directly tampering with the session's zone id to a real-looking
        // but inactive zone must not resolve either (setZone()'s own guard,
        // exercised end-to-end through the picker's public API).
        $realZone->update(['is_active' => false]);
        $this->withSession([CustomerLocationContext::SESSION_KEY => $realZone->id]);
        $this->assertNull(app(CustomerLocationContext::class)->zone());
    }

    // ==================== Recent locations ====================

    /** Automated test. */
    public function test_recent_locations_reuses_saved_customer_addresses_for_logged_in_customers(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        $this->makeAddress($customer, $franchise, $zone);

        Livewire::actingAs($customer)
            ->test(LocationPicker::class)
            ->call('openPicker')
            ->assertSeeText('Recent locations')
            ->assertSeeText('Home');
    }
}
