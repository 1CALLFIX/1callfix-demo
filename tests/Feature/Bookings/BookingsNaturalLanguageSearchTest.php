<?php

namespace Tests\Feature\Bookings;

use App\Livewire\Bookings\Index;
use App\Models\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Admin Polish + AI session, Part 2 item 2 — end-to-end regression for the
 * natural-language search box actually wired into Bookings\Index, on top of
 * BookingNaturalLanguageFilterTest's own unit-level parser coverage. Also
 * confirms it doesn't regress the pre-existing server-side statusFilter/
 * search filtering (item 44/46/48/49/50 pattern).
 */
class BookingsNaturalLanguageSearchTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    private function extraBooking(array $scenario, string $status): Booking
    {
        return Booking::create([
            'code' => 'TST-'.now()->format('dm').'-'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'franchise_id' => $scenario['franchise']->id, 'zone_id' => $scenario['zone']->id,
            'customer_id' => $scenario['customer']->id, 'service_id' => $scenario['service']->id,
            'address_id' => $scenario['address']->id, 'status' => $status,
            'price_quoted' => 500, 'payment_status' => 'pending', 'payment_method' => 'online',
        ]);
    }

    public function test_typing_a_status_keyword_filters_the_list(): void
    {
        $scenario = $this->makeBookingScenario('cancelled');
        $this->extraBooking($scenario, 'completed');
        $admin = $this->makeUserWithPermission('bookings.view', 'global');

        Livewire::actingAs($admin)->test(Index::class)
            ->set('nlQuery', 'cancelled bookings')
            ->assertSee($scenario['booking']->code)
            ->assertSet('statusFilter', 'cancelled');
    }

    public function test_typing_a_zone_name_filters_to_that_zone_only(): void
    {
        $scenarioA = $this->makeBookingScenario('completed');
        $scenarioA['zone']->update(['name' => 'Riverside']);
        $scenarioB = $this->makeBookingScenario('completed');

        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(Index::class)
            ->set('nlQuery', 'bookings in riverside')
            ->assertSee($scenarioA['booking']->code)
            ->assertDontSee($scenarioB['booking']->code);
    }

    public function test_clearing_the_nl_query_restores_the_full_list(): void
    {
        $scenario = $this->makeBookingScenario('cancelled');
        $other = $this->extraBooking($scenario, 'completed');
        $admin = $this->makeUserWithPermission('bookings.view', 'global');

        Livewire::actingAs($admin)->test(Index::class)
            ->set('nlQuery', 'cancelled bookings')
            ->assertDontSee($other->code)
            ->call('clearNlQuery')
            ->assertSee($other->code)
            ->assertSee($scenario['booking']->code);
    }

    public function test_manually_picking_a_status_chip_overrides_a_previous_nl_zone_filter(): void
    {
        $scenarioA = $this->makeBookingScenario('cancelled');
        $scenarioA['zone']->update(['name' => 'Riverside']);
        $scenarioB = $this->makeBookingScenario('completed');

        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(Index::class)
            ->set('nlQuery', 'bookings in riverside')
            ->assertDontSee($scenarioB['booking']->code)
            // Manually clearing back to "All" must drop the zone filter the
            // NL search left applied, not silently combine with it.
            ->call('selectStatus', '')
            ->assertSee($scenarioB['booking']->code)
            ->assertSee($scenarioA['booking']->code);
    }

    public function test_nl_search_never_bypasses_row_level_scoping(): void
    {
        // A franchise-scoped actor typing a zone name outside their own
        // scope must not see that zone's bookings — the parser only ever
        // resolves against the passed-in zone list, and the query itself
        // still runs through the SAME AuthorizationService::scopeQuery()
        // every other filter on this screen already goes through.
        $scenarioOutside = $this->makeBookingScenario('completed');
        $scenarioOutside['zone']->update(['name' => 'Faraway']);

        $scenarioInside = $this->makeBookingScenario('completed');
        $actor = $this->makeUserWithPermission('bookings.view', 'franchise', $scenarioInside['franchise']->id);

        Livewire::actingAs($actor)->test(Index::class)
            ->set('nlQuery', 'bookings in faraway')
            ->assertDontSee($scenarioOutside['booking']->code);
    }
}
