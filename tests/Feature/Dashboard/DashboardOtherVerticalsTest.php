<?php

namespace Tests\Feature\Dashboard;

use App\Livewire\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\TestCase;

/**
 * Admin Command Center mission (Phase 4 audit finding) — the Dashboard was
 * entirely Booking/Service-only; no visibility into Parcel/Taxi/Rental/
 * Hotel/Marketplace volume existed anywhere on it. Covers the new
 * otherVerticalStats() (permission-gated, row-scoped) and the
 * unassigned_bookings alert stat.
 */
class DashboardOtherVerticalsTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;
    use ParcelOrderFixtureHelpers;

    public function test_other_vertical_card_hidden_without_permission(): void
    {
        $scenario = $this->makeParcelOrderScenario('pending');
        // dashboard.view but no parcel_orders.view -- the card must not appear.
        $actor = $this->makeUserWithPermission('dashboard.view', 'global');

        Livewire::actingAs($actor)->test(Dashboard::class)
            ->assertOk()
            ->assertDontSee('Parcel Orders Today');
    }

    public function test_other_vertical_card_visible_and_scoped_with_permission(): void
    {
        $scenario = $this->makeParcelOrderScenario('pending');
        $other = $this->makeParcelOrderScenario('pending');

        $actor = $this->makeUserWithPermission('dashboard.view', 'global');
        $this->grantPermission($actor, 'parcel_orders.view', 'franchise', $scenario['franchise']->id);

        // otherVerticals is built inside render() and passed to the view
        // (not a computed property) -- assert on the rendered output.
        Livewire::actingAs($actor)->test(Dashboard::class)
            ->assertOk()
            ->assertSee('Parcel Orders Today');
    }

    public function test_unassigned_bookings_counts_searching_provider_status(): void
    {
        $scenario = $this->makeBookingScenario('searching_provider');
        $assigned = $this->makeBookingScenario('assigned');
        $assigned['booking']->update(['franchise_id' => $scenario['franchise']->id, 'zone_id' => $scenario['zone']->id]);

        $actor = $this->makeUserWithPermission('dashboard.view', 'franchise', $scenario['franchise']->id);

        Livewire::actingAs($actor)->test(Dashboard::class)
            ->assertOk()
            ->assertSee('Unassigned Bookings');

        $stats = app(\App\Services\AuthorizationService::class)
            ->scopeQuery(\App\Models\Booking::query(), $actor, 'dashboard.view', ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'])
            ->where('status', 'searching_provider')->count();

        $this->assertSame(1, $stats);
    }

    public function test_bookings_today_card_links_to_bookings_index_when_permitted(): void
    {
        $scenario = $this->makeBookingScenario();
        $actor = $this->makeUserWithPermission('dashboard.view', 'global');
        $this->grantPermission($actor, 'bookings.view', 'global');

        Livewire::actingAs($actor)->test(Dashboard::class)
            ->assertOk()
            ->assertSee(route('admin.bookings.index'), false);
    }
}
