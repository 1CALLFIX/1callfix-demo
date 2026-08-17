<?php

namespace Tests\Feature\Commissions;

use App\Exports\CommissionsExport;
use App\Livewire\Commissions\Index as CommissionsIndex;
use App\Models\Commission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\TestCase;

/**
 * 2026-08-17 hardening. Same bug class as PaymentsScopeAuthorizationTest,
 * worse before this fix (not even the old dual-candidate form -- purely
 * `booking.*`): Commissions\Index::baseQuery() and CommissionsExport both
 * only ever scoped/rendered the `booking` relation. A franchise-scoped
 * `commissions.view` grant saw ZERO parcel/taxi/property/marketplace
 * commission rows (fail-closed, not a leak) and, once visible to a global/
 * super_admin viewer, every column resolved through `$c->booking` (always
 * null for those rows) rendered "—".
 */
class CommissionsScopeTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;
    use MarketplaceFixtureHelpers;

    public function test_view_denied_without_permission(): void
    {
        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(CommissionsIndex::class)->assertForbidden();
    }

    public function test_booking_purpose_commission_is_scoped_to_the_actors_own_zone(): void
    {
        $mine = $this->makeAssignedBookingScenario();
        $other = $this->makeAssignedBookingScenario();
        $mineCommission = Commission::create(['booking_id' => $mine['booking']->id, 'provider_commission' => 10, 'franchise_commission' => 5, 'platform_commission' => 5]);
        $otherCommission = Commission::create(['booking_id' => $other['booking']->id, 'provider_commission' => 10, 'franchise_commission' => 5, 'platform_commission' => 5]);
        $actor = $this->makeUserWithPermission('commissions.view', 'zone', $mine['zone']->id);

        $ids = Livewire::actingAs($actor)->test(CommissionsIndex::class)
            ->viewData('commissions')->pluck('id')->all();

        $this->assertContains($mineCommission->id, $ids);
        $this->assertNotContains($otherCommission->id, $ids);
    }

    /** The real regression -- this failed before the scopeColumns() fix (the row was simply absent). */
    public function test_marketplace_order_purpose_commission_is_visible_and_scoped_to_the_actors_own_zone(): void
    {
        $mine = $this->makeMarketplaceOrderScenario('completed');
        $other = $this->makeMarketplaceOrderScenario('completed');
        $mineCommission = Commission::create(['marketplace_order_id' => $mine['order']->id, 'provider_commission' => 10, 'franchise_commission' => 5, 'platform_commission' => 5]);
        $otherCommission = Commission::create(['marketplace_order_id' => $other['order']->id, 'provider_commission' => 10, 'franchise_commission' => 5, 'platform_commission' => 5]);
        $actor = $this->makeUserWithPermission('commissions.view', 'zone', $mine['zone']->id);

        $ids = Livewire::actingAs($actor)->test(CommissionsIndex::class)
            ->viewData('commissions')->pluck('id')->all();

        $this->assertContains($mineCommission->id, $ids, 'A marketplace_order commission within the actors own zone must be visible.');
        $this->assertNotContains($otherCommission->id, $ids);
    }

    public function test_search_finds_a_marketplace_order_by_its_own_code(): void
    {
        $scenario = $this->makeMarketplaceOrderScenario('completed');
        Commission::create(['marketplace_order_id' => $scenario['order']->id, 'provider_commission' => 10, 'franchise_commission' => 5, 'platform_commission' => 5]);
        $actor = $this->makeUserWithPermission('commissions.view', 'global');

        $results = Livewire::actingAs($actor)->test(CommissionsIndex::class)
            ->set('search', $scenario['order']->code)
            ->viewData('commissions');

        $this->assertSame(1, $results->total());
    }

    public function test_franchise_filter_matches_a_marketplace_order_commission(): void
    {
        $mine = $this->makeMarketplaceOrderScenario('completed');
        $other = $this->makeMarketplaceOrderScenario('completed');
        Commission::create(['marketplace_order_id' => $mine['order']->id, 'provider_commission' => 10, 'franchise_commission' => 5, 'platform_commission' => 5]);
        Commission::create(['marketplace_order_id' => $other['order']->id, 'provider_commission' => 10, 'franchise_commission' => 5, 'platform_commission' => 5]);
        $actor = $this->makeUserWithPermission('commissions.view', 'global');

        $results = Livewire::actingAs($actor)->test(CommissionsIndex::class)
            ->set('franchiseFilter', $mine['franchise']->id)
            ->viewData('commissions');

        $this->assertSame(1, $results->total());
    }

    public function test_super_admin_sees_every_zones_commissions(): void
    {
        $mine = $this->makeAssignedBookingScenario();
        $other = $this->makeMarketplaceOrderScenario('completed');
        Commission::create(['booking_id' => $mine['booking']->id, 'provider_commission' => 10, 'franchise_commission' => 5, 'platform_commission' => 5]);
        Commission::create(['marketplace_order_id' => $other['order']->id, 'provider_commission' => 10, 'franchise_commission' => 5, 'platform_commission' => 5]);
        $admin = $this->makeSuperAdmin();

        $results = Livewire::actingAs($admin)->test(CommissionsIndex::class)->viewData('commissions');

        $this->assertSame(2, $results->total());
    }

    // ============================== Export ==============================

    public function test_export_includes_a_marketplace_order_commission_within_scope(): void
    {
        $mine = $this->makeMarketplaceOrderScenario('completed');
        $other = $this->makeMarketplaceOrderScenario('completed');
        Commission::create(['marketplace_order_id' => $mine['order']->id, 'provider_commission' => 10, 'franchise_commission' => 5, 'platform_commission' => 5]);
        Commission::create(['marketplace_order_id' => $other['order']->id, 'provider_commission' => 10, 'franchise_commission' => 5, 'platform_commission' => 5]);
        $actor = $this->makeUserWithPermission('commissions.view', 'zone', $mine['zone']->id);

        $rows = (new CommissionsExport($actor))->collection();

        $this->assertCount(1, $rows);
        $this->assertSame($mine['order']->id, $rows->first()->marketplace_order_id);
    }

    public function test_export_map_resolves_order_code_franchise_and_earner_for_a_marketplace_order_row(): void
    {
        $scenario = $this->makeMarketplaceOrderScenario('completed');
        $commission = Commission::create(['marketplace_order_id' => $scenario['order']->id, 'provider_commission' => 10, 'franchise_commission' => 5, 'platform_commission' => 5]);
        $commission->load(['marketplaceOrder.franchise', 'marketplaceOrder.store.provider.user']);

        $row = (new CommissionsExport($scenario['customer']))->map($commission);

        $this->assertSame($scenario['order']->code, $row[0]);
        $this->assertSame('marketplace_order', $row[1]);
        $this->assertSame($scenario['franchise']->name, $row[2]);
        $this->assertSame($scenario['owner']->user->name, $row[3]);
    }
}
