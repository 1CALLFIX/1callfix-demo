<?php

namespace Tests\Feature\FlashSales;

use App\Livewire\FlashSales\Manage as FlashSalesManage;
use App\Models\Badge;
use App\Models\FlashSale;
use App\Models\FlashSaleRedemption;
use App\Models\FlashSaleTarget;
use App\Models\Service;
use App\Services\FlashSaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Flash Sale Engine (Phase 2 of the full-day mission). Server-side time is
 * authoritative throughout -- FlashSale::isCurrentlyActive() is re-derived
 * from starts_at/ends_at+status on every read, never trusting a stale
 * status column, verified below via time travel rather than assumed.
 */
class FlashSaleEngineTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    private function makeService(): Service
    {
        [, $service] = $this->makeCategoryAndService();

        return $service;
    }

    private function makeSale(array $overrides = []): FlashSale
    {
        return FlashSale::create(array_merge([
            'name' => 'Test Sale', 'customer_title' => 'Test Sale!', 'type' => 'urgent_sale',
            'status' => 'draft', 'scope_type' => 'global', 'discount_type' => 'percent',
            'discount_value' => 20, 'min_final_price' => 0,
        ], $overrides));
    }

    private function targeting(FlashSale $sale, Service $service): FlashSale
    {
        FlashSaleTarget::create(['flash_sale_id' => $sale->id, 'service_id' => $service->id]);

        return $sale;
    }

    // ============================== Lifecycle ==============================

    public function test_draft_schedules_into_scheduled(): void
    {
        $sale = $this->makeSale();

        $scheduled = app(FlashSaleService::class)->schedule($sale, now()->addHour(), now()->addDay());

        $this->assertSame('scheduled', $scheduled->status);
    }

    public function test_schedule_rejects_end_before_start(): void
    {
        $sale = $this->makeSale();

        $this->expectException(\RuntimeException::class);
        app(FlashSaleService::class)->schedule($sale, now()->addDay(), now()->addHour());
    }

    public function test_scheduled_goes_live_now(): void
    {
        $sale = $this->makeSale(['status' => 'scheduled', 'starts_at' => now()->addHour(), 'ends_at' => now()->addDay()]);

        $live = app(FlashSaleService::class)->goLive($sale);

        $this->assertSame('live', $live->status);
        $this->assertTrue($live->starts_at->lessThanOrEqualTo(now()));
    }

    public function test_live_can_be_paused_and_resumed(): void
    {
        $sale = $this->makeSale(['status' => 'live', 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay()]);
        $service = app(FlashSaleService::class);

        $paused = $service->pause($sale);
        $this->assertSame('paused', $paused->status);

        $resumed = $service->resume($paused);
        $this->assertSame('live', $resumed->status, 'Resuming after starts_at has passed must land back in live, not scheduled.');
    }

    public function test_resuming_before_starts_at_lands_in_scheduled(): void
    {
        $sale = $this->makeSale(['status' => 'paused', 'starts_at' => now()->addHour(), 'ends_at' => now()->addDay()]);

        $resumed = app(FlashSaleService::class)->resume($sale);

        $this->assertSame('scheduled', $resumed->status);
    }

    public function test_live_can_be_completed(): void
    {
        $sale = $this->makeSale(['status' => 'live', 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay()]);

        $completed = app(FlashSaleService::class)->complete($sale);

        $this->assertSame('completed', $completed->status);
    }

    public function test_any_non_terminal_state_can_be_cancelled(): void
    {
        foreach (['draft', 'scheduled', 'live', 'paused'] as $status) {
            $sale = $this->makeSale(['status' => $status]);
            $cancelled = app(FlashSaleService::class)->cancel($sale);
            $this->assertSame('cancelled', $cancelled->status);
        }
    }

    public function test_completed_and_cancelled_are_terminal_no_further_transitions(): void
    {
        $completed = $this->makeSale(['status' => 'completed']);
        $this->expectException(\RuntimeException::class);
        app(FlashSaleService::class)->goLive($completed);
    }

    public function test_a_cancelled_sale_cannot_be_resumed(): void
    {
        $cancelled = $this->makeSale(['status' => 'cancelled']);
        $this->expectException(\RuntimeException::class);
        app(FlashSaleService::class)->resume($cancelled);
    }

    public function test_draft_cannot_go_directly_live(): void
    {
        $sale = $this->makeSale(['status' => 'draft']);
        $this->expectException(\RuntimeException::class);
        app(FlashSaleService::class)->goLive($sale);
    }

    // ============================== Server-time authoritative / expiry ==============================

    public function test_a_scheduled_sale_becomes_active_the_instant_starts_at_passes_with_no_sync_step(): void
    {
        $sale = $this->makeSale(['status' => 'scheduled', 'starts_at' => now()->addMinute(), 'ends_at' => now()->addDay()]);

        $this->assertFalse($sale->isCurrentlyActive());

        $this->travel(2)->minutes();

        $this->assertTrue($sale->fresh()->isCurrentlyActive(), 'status column still literally says scheduled -- activeness must be time-derived, not status-derived.');
    }

    public function test_a_live_sale_stops_applying_the_instant_ends_at_passes(): void
    {
        $sale = $this->makeSale(['status' => 'live', 'starts_at' => now()->subHour(), 'ends_at' => now()->addMinute()]);

        $this->assertTrue($sale->isCurrentlyActive());

        $this->travel(2)->minutes();

        $this->assertFalse($sale->fresh()->isCurrentlyActive());
    }

    public function test_paused_suppresses_the_discount_even_mid_window(): void
    {
        $sale = $this->makeSale(['status' => 'paused', 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay()]);

        $this->assertFalse($sale->isCurrentlyActive(), 'paused is a pure admin override -- must suppress regardless of the time window.');
    }

    public function test_a_draft_sale_never_applies_even_with_a_valid_time_window(): void
    {
        $sale = $this->makeSale(['status' => 'draft', 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay()]);

        $this->assertFalse($sale->isCurrentlyActive());
    }

    // ============================== Pricing ==============================

    public function test_percent_discount_computes_correctly(): void
    {
        $sale = $this->makeSale(['discount_type' => 'percent', 'discount_value' => 25]);

        $this->assertSame(75.0, $sale->computeFinalPrice(100));
    }

    public function test_flat_discount_computes_correctly(): void
    {
        $sale = $this->makeSale(['discount_type' => 'flat', 'discount_value' => 30]);

        $this->assertSame(70.0, $sale->computeFinalPrice(100));
    }

    public function test_max_discount_caps_a_percent_discount(): void
    {
        $sale = $this->makeSale(['discount_type' => 'percent', 'discount_value' => 90, 'max_discount' => 20]);

        $this->assertSame(80.0, $sale->computeFinalPrice(100), 'Discount must be capped at max_discount (20), not the full 90%.');
    }

    public function test_min_final_price_floors_the_result(): void
    {
        $sale = $this->makeSale(['discount_type' => 'flat', 'discount_value' => 90, 'min_final_price' => 20]);

        $this->assertSame(20.0, $sale->computeFinalPrice(100));
    }

    public function test_price_never_goes_negative_even_without_a_min_final_price_set(): void
    {
        $sale = $this->makeSale(['discount_type' => 'flat', 'discount_value' => 500, 'min_final_price' => 0]);

        $this->assertSame(0.0, $sale->computeFinalPrice(100));
    }

    public function test_priceFor_returns_null_when_no_active_sale_targets_the_service(): void
    {
        $service = $this->makeService();

        $this->assertNull(app(FlashSaleService::class)->priceFor($service, 500));
    }

    public function test_priceFor_returns_the_discounted_price_for_an_active_targeted_sale(): void
    {
        $service = $this->makeService();
        $sale = $this->targeting($this->makeSale(['status' => 'live', 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay(), 'discount_value' => 20]), $service);

        $result = app(FlashSaleService::class)->priceFor($service, 500);

        $this->assertNotNull($result);
        $this->assertSame(400.0, $result['final_price']);
        $this->assertSame($sale->id, $result['flash_sale_id']);
    }

    public function test_priceFor_ignores_a_sale_targeting_a_different_service(): void
    {
        $service = $this->makeService();
        $otherService = $this->makeService();
        $this->targeting($this->makeSale(['status' => 'live', 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay()]), $otherService);

        $this->assertNull(app(FlashSaleService::class)->priceFor($service, 500));
    }

    // ============================== Scope ==============================

    public function test_a_zone_scoped_sale_only_prices_for_a_viewer_in_that_zone(): void
    {
        $service = $this->makeService();
        [, , , $zone] = $this->makeFranchiseTree();
        [, , , $otherZone] = $this->makeFranchiseTree();
        $this->targeting($this->makeSale(['status' => 'live', 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay(), 'scope_type' => 'zone', 'scope_id' => $zone->id]), $service);

        $this->assertNotNull(app(FlashSaleService::class)->priceFor($service, 500, ['zone_id' => $zone->id]));
        $this->assertNull(app(FlashSaleService::class)->priceFor($service, 500, ['zone_id' => $otherZone->id]));
        $this->assertNull(app(FlashSaleService::class)->priceFor($service, 500, []));
    }

    public function test_a_global_sale_prices_for_every_viewer(): void
    {
        $service = $this->makeService();
        $this->targeting($this->makeSale(['status' => 'live', 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay(), 'scope_type' => 'global']), $service);

        $this->assertNotNull(app(FlashSaleService::class)->priceFor($service, 500, ['zone_id' => 12345]));
        $this->assertNotNull(app(FlashSaleService::class)->priceFor($service, 500, []));
    }

    // ============================== Duplicate targets ==============================

    public function test_the_same_service_cannot_be_added_twice_to_the_same_sale(): void
    {
        $service = $this->makeService();
        $sale = $this->makeSale();
        app(FlashSaleService::class)->addTarget($sale, $service);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already a target');
        app(FlashSaleService::class)->addTarget($sale, $service);
    }

    public function test_two_active_sales_cannot_target_the_same_service_at_the_same_scope(): void
    {
        $service = $this->makeService();
        $saleA = $this->makeSale(['status' => 'live', 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay()]);
        app(FlashSaleService::class)->addTarget($saleA, $service);
        $saleB = $this->makeSale(['status' => 'live', 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay()]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already has an active flash sale');
        app(FlashSaleService::class)->addTarget($saleB, $service);
    }

    public function test_a_draft_sale_targeting_the_same_service_does_not_block_a_second_draft(): void
    {
        $service = $this->makeService();
        $saleA = $this->makeSale(['status' => 'draft']); // not active -- must not block
        app(FlashSaleService::class)->addTarget($saleA, $service);
        $saleB = $this->makeSale(['status' => 'draft']);

        $target = app(FlashSaleService::class)->addTarget($saleB, $service);

        $this->assertNotNull($target->id);
    }

    // ============================== Inventory / quantity limits ==============================

    public function test_redemption_is_recorded_with_the_correct_discount(): void
    {
        $service = $this->makeService();
        $customer = $this->makeCustomer();
        $sale = $this->targeting($this->makeSale(['status' => 'live', 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay(), 'discount_value' => 10]), $service);

        $redemption = app(FlashSaleService::class)->redeem($sale, $service, $customer, 500);

        $this->assertSame(450.0, (float) $redemption->final_price);
        $this->assertSame(50.0, (float) $redemption->discount_applied);
    }

    public function test_total_quantity_limit_is_enforced(): void
    {
        $service = $this->makeService();
        $sale = $this->targeting($this->makeSale(['status' => 'live', 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay(), 'total_quantity_limit' => 1]), $service);
        $customerA = $this->makeCustomer();
        $customerB = $this->makeCustomer();

        app(FlashSaleService::class)->redeem($sale, $service, $customerA, 500);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('sold out');
        app(FlashSaleService::class)->redeem($sale, $service, $customerB, 500);
    }

    public function test_per_customer_limit_is_enforced(): void
    {
        $service = $this->makeService();
        $sale = $this->targeting($this->makeSale(['status' => 'live', 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay(), 'per_customer_limit' => 1]), $service);
        $customer = $this->makeCustomer();

        app(FlashSaleService::class)->redeem($sale, $service, $customer, 500);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('maximum number of times');
        app(FlashSaleService::class)->redeem($sale, $service, $customer, 500);
    }

    public function test_a_different_customer_is_unaffected_by_another_customers_per_customer_limit(): void
    {
        $service = $this->makeService();
        $sale = $this->targeting($this->makeSale(['status' => 'live', 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay(), 'per_customer_limit' => 1]), $service);
        $customerA = $this->makeCustomer();
        $customerB = $this->makeCustomer();

        app(FlashSaleService::class)->redeem($sale, $service, $customerA, 500);
        $redemption = app(FlashSaleService::class)->redeem($sale, $service, $customerB, 500);

        $this->assertNotNull($redemption->id);
    }

    public function test_redemption_is_rejected_once_the_sale_is_no_longer_active(): void
    {
        $service = $this->makeService();
        $sale = $this->targeting($this->makeSale(['status' => 'live', 'starts_at' => now()->subDay(), 'ends_at' => now()->subHour()]), $service);
        $customer = $this->makeCustomer();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no longer active');
        app(FlashSaleService::class)->redeem($sale, $service, $customer, 500);
    }

    public function test_priceFor_excludes_a_sold_out_sale(): void
    {
        $service = $this->makeService();
        $sale = $this->targeting($this->makeSale(['status' => 'live', 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay(), 'total_quantity_limit' => 1]), $service);
        app(FlashSaleService::class)->redeem($sale, $service, $this->makeCustomer(), 500);

        $this->assertNull(app(FlashSaleService::class)->priceFor($service, 500), 'A sold-out sale must not be offered to a new customer, even though the time window is still open.');
    }

    public function test_unlimited_quantity_never_blocks_redemption(): void
    {
        $service = $this->makeService();
        $sale = $this->targeting($this->makeSale(['status' => 'live', 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay(), 'total_quantity_limit' => null]), $service);

        for ($i = 0; $i < 5; $i++) {
            app(FlashSaleService::class)->redeem($sale, $service, $this->makeCustomer(), 500);
        }

        $this->assertSame(5, FlashSaleRedemption::where('flash_sale_id', $sale->id)->count());
    }

    // ============================== Customer-facing visibility ==============================

    public function test_priceFor_result_is_json_serializable(): void
    {
        $service = $this->makeService();
        $this->targeting($this->makeSale(['status' => 'live', 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay()]), $service);

        $result = app(FlashSaleService::class)->priceFor($service, 500);

        $this->assertJson(json_encode($result));
        $this->assertArrayHasKey('final_price', $result);
        $this->assertArrayHasKey('remaining_quantity', $result);
    }

    public function test_badge_integration_links_a_badge_to_a_sale(): void
    {
        $badge = Badge::where('key', 'flash_sale')->first();
        $sale = $this->makeSale(['badge_id' => $badge->id]);

        $this->assertSame($badge->id, $sale->fresh()->badge->id);
    }

    // ============================== Admin authorization ==============================

    public function test_screen_denied_without_flash_sales_view_permission(): void
    {
        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(FlashSalesManage::class)->assertForbidden();
    }

    public function test_create_denied_outside_the_actors_own_zone(): void
    {
        [, , , $myZone] = $this->makeFranchiseTree();
        [, , , $otherZone] = $this->makeFranchiseTree();
        $actor = $this->makeUserWithPermission('flash_sales.manage', 'zone', $myZone->id);
        $this->grantPermission($actor, 'flash_sales.view', 'zone', $myZone->id);

        Livewire::actingAs($actor)->test(FlashSalesManage::class)
            ->set('name', 'Test')->set('customerTitle', 'Test!')
            ->set('scopeType', 'zone')->set('scopeZoneId', $otherZone->id)
            ->call('create')
            ->assertSet('flashType', 'error');

        $this->assertDatabaseMissing('flash_sales', ['name' => 'Test']);
    }

    public function test_create_allowed_within_the_actors_own_zone(): void
    {
        [, , , $myZone] = $this->makeFranchiseTree();
        $actor = $this->makeUserWithPermission('flash_sales.manage', 'zone', $myZone->id);
        $this->grantPermission($actor, 'flash_sales.view', 'zone', $myZone->id);

        Livewire::actingAs($actor)->test(FlashSalesManage::class)
            ->set('name', 'Zone Sale')->set('customerTitle', 'Zone Sale!')
            ->set('scopeType', 'zone')->set('scopeZoneId', $myZone->id)
            ->call('create')
            ->assertSet('flashType', 'success');

        $this->assertDatabaseHas('flash_sales', ['name' => 'Zone Sale', 'scope_type' => 'zone', 'scope_id' => $myZone->id]);
    }

    public function test_lifecycle_action_denied_outside_the_actors_own_zone(): void
    {
        [, , , $zone] = $this->makeFranchiseTree();
        $sale = $this->makeSale(['status' => 'draft', 'scope_type' => 'zone', 'scope_id' => $zone->id]);
        $actor = $this->makeUserWithPermission('flash_sales.manage', 'zone', 999999);
        $this->grantPermission($actor, 'flash_sales.view', 'global');

        Livewire::actingAs($actor)->test(FlashSalesManage::class)
            ->call('lifecycleAction', $sale->id, 'cancel')
            ->assertSet('flashType', 'error');

        $this->assertSame('draft', $sale->fresh()->status);
    }

    public function test_super_admin_can_manage_every_scope(): void
    {
        [, , , $zone] = $this->makeFranchiseTree();
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(FlashSalesManage::class)
            ->set('name', 'Admin Sale')->set('customerTitle', 'Admin Sale!')
            ->set('scopeType', 'zone')->set('scopeZoneId', $zone->id)
            ->call('create')
            ->assertSet('flashType', 'success');
    }

    /**
     * Admin Command Center mission (KNOWN_RISKS_AND_DECISIONS.md item 44) --
     * render() switched from an unbounded ->get() + in-memory
     * visibleAmong() to a lightweight id-projection + whereIn() +
     * paginate() (matching NotificationCenter\Manage/Payouts\Manage's own
     * established pattern for this exact scope_type/scope_id shape).
     * Confirms row-level scope survives the rewrite.
     */
    public function test_sales_list_is_scoped_to_the_actors_own_zone_after_the_pagination_rewrite(): void
    {
        [, , , $myZone] = $this->makeFranchiseTree();
        [, , , $otherZone] = $this->makeFranchiseTree();
        $mine = $this->makeSale(['name' => 'Mine', 'scope_type' => 'zone', 'scope_id' => $myZone->id]);
        $other = $this->makeSale(['name' => 'Other', 'scope_type' => 'zone', 'scope_id' => $otherZone->id]);
        $actor = $this->makeUserWithPermission('flash_sales.view', 'zone', $myZone->id);

        Livewire::actingAs($actor)->test(FlashSalesManage::class)
            ->assertOk()
            ->assertSee('Mine')
            ->assertDontSee('Other');
    }

    public function test_sales_list_paginates_instead_of_returning_the_whole_table(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->makeSale(['name' => "Sale {$i}"]);
        }
        $admin = $this->makeSuperAdmin();

        $sales = Livewire::actingAs($admin)->test(FlashSalesManage::class)
            ->viewData('sales');

        $this->assertSame(15, $sales->count());
        $this->assertSame(20, $sales->total());
    }

    public function test_percent_discount_over_100_is_rejected_at_creation(): void
    {
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(FlashSalesManage::class)
            ->set('name', 'Bad Sale')->set('customerTitle', 'Bad Sale!')
            ->set('discountType', 'percent')->set('discountValue', '150')
            ->call('create')
            ->assertHasErrors(['discountValue']);

        $this->assertDatabaseMissing('flash_sales', ['name' => 'Bad Sale']);
    }
}
