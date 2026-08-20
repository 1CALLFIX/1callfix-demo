<?php

namespace Tests\Feature\GlobalSearch;

use App\Livewire\GlobalSearch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\TestCase;

/**
 * Admin Command Center mission, Phase 16 (Global Search / Command Palette).
 * Covers: minimum query length, permission gating per result group,
 * row-level scope (never surfaces a record the searcher couldn't already
 * see on that vertical's own admin screen), and super-admin bypass.
 */
class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;
    use ParcelOrderFixtureHelpers;

    private function makeCustomerIn($franchise, $zone, string $phoneSuffix): User
    {
        return User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Search Customer',
            'phone' => '9'.$phoneSuffix,
            'role' => 'customer', 'status' => 'active',
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
        ]);
    }

    public function test_query_shorter_than_two_characters_returns_no_results(): void
    {
        $scenario = $this->makeBookingScenario();
        $actor = $this->makeUserWithPermission('bookings.view', 'franchise', $scenario['franchise']->id);

        $results = Livewire::actingAs($actor)->test(GlobalSearch::class)
            ->set('query', 'a')
            ->get('results');

        $this->assertSame([], $results);
    }

    public function test_booking_search_is_denied_without_bookings_view_permission(): void
    {
        $scenario = $this->makeBookingScenario();
        $actor = $this->makeUserWithNoPermissions();

        $results = Livewire::actingAs($actor)->test(GlobalSearch::class)
            ->set('query', $scenario['booking']->code)
            ->get('results');

        $this->assertArrayNotHasKey('Bookings', $results);
    }

    public function test_booking_search_finds_a_booking_by_code_within_scope(): void
    {
        $scenario = $this->makeBookingScenario();
        $actor = $this->makeUserWithPermission('bookings.view', 'franchise', $scenario['franchise']->id);

        Livewire::actingAs($actor)->test(GlobalSearch::class)
            ->set('open', true)
            ->set('query', $scenario['booking']->code)
            ->assertSee($scenario['booking']->code);
    }

    public function test_booking_search_hides_a_booking_outside_the_actors_franchise(): void
    {
        $mine = $this->makeBookingScenario();
        $other = $this->makeBookingScenario();
        $actor = $this->makeUserWithPermission('bookings.view', 'franchise', $mine['franchise']->id);

        Livewire::actingAs($actor)->test(GlobalSearch::class)
            ->set('open', true)
            ->set('query', $other['booking']->code)
            ->assertDontSee($other['booking']->code);
    }

    public function test_customer_search_finds_by_phone_within_scope(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomerIn($franchise, $zone, '5551234');
        $actor = $this->makeUserWithPermission('customers.view', 'franchise', $franchise->id);

        Livewire::actingAs($actor)->test(GlobalSearch::class)
            ->set('open', true)
            ->set('query', '5551234')
            ->assertSee('5551234');
    }

    public function test_customer_search_hides_a_customer_outside_the_actors_franchise(): void
    {
        [$c1, $ci1, $franchise, $zone] = $this->makeFranchiseTree();
        $mine = $this->makeCustomerIn($franchise, $zone, '5551111');
        [$c2, $ci2, $f2, $z2] = $this->makeFranchiseTree();
        $other = $this->makeCustomerIn($f2, $z2, '5552222');

        $actor = $this->makeUserWithPermission('customers.view', 'franchise', $franchise->id);

        Livewire::actingAs($actor)->test(GlobalSearch::class)
            ->set('open', true)
            ->set('query', '555')
            ->assertSee('5551111')
            ->assertDontSee('5552222');
    }

    public function test_parcel_order_search_is_gated_by_parcel_orders_view_permission(): void
    {
        $scenario = $this->makeParcelOrderScenario('pending');
        $actor = $this->makeUserWithNoPermissions();

        $results = Livewire::actingAs($actor)->test(GlobalSearch::class)
            ->set('query', $scenario['order']->code)
            ->get('results');

        $this->assertArrayNotHasKey('Parcel Orders', $results);
    }

    public function test_parcel_order_search_finds_by_code_when_permitted(): void
    {
        $scenario = $this->makeParcelOrderScenario('pending');
        $actor = $this->makeUserWithPermission('parcel_orders.view', 'franchise', $scenario['franchise']->id);

        Livewire::actingAs($actor)->test(GlobalSearch::class)
            ->set('open', true)
            ->set('query', $scenario['order']->code)
            ->assertSee($scenario['order']->code);
    }

    public function test_super_admin_sees_results_across_every_franchise(): void
    {
        $mine = $this->makeBookingScenario();
        $other = $this->makeBookingScenario();
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(GlobalSearch::class)
            ->set('open', true)
            ->set('query', $mine['booking']->code)
            ->assertSee($mine['booking']->code);

        Livewire::actingAs($admin)->test(GlobalSearch::class)
            ->set('open', true)
            ->set('query', $other['booking']->code)
            ->assertSee($other['booking']->code);
    }

    public function test_open_and_close_toggle_the_palette_visibility(): void
    {
        $actor = $this->makeUserWithPermission('bookings.view', 'global');

        Livewire::actingAs($actor)->test(GlobalSearch::class)
            ->assertSet('open', false)
            ->call('openPalette')
            ->assertSet('open', true)
            ->set('query', 'something')
            ->call('close')
            ->assertSet('open', false)
            ->assertSet('query', '');
    }
}
