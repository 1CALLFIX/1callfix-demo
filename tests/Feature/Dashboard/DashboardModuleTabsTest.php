<?php

namespace Tests\Feature\Dashboard;

use App\Livewire\Dashboard;
use App\Livewire\MarketplaceOrders\Manage as MarketplaceOrdersManage;
use App\Support\ModuleSales;
use App\Support\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\TestCase;

/**
 * Owner request 2026-11-03: the dashboard is tabbed by module, and the All modules tab shows every module's
 * orders and sales in one table with a grand total, split online / cash, on each module's own permission + scope.
 */
class DashboardModuleTabsTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;
    use ParcelOrderFixtureHelpers;
    use MarketplaceFixtureHelpers;

    /** Owner 2026-11-09: the dashboard shows only modules switched on somewhere (Admin → Modules). */
    private function switchOn(string ...$codes): void
    {
        $franchiseId = \App\Models\Franchise::query()->value('id');
        foreach ($codes as $code) {
            \App\Models\Module::where('code', $code)->update(['is_implemented' => true]);
            app(\App\Services\ModuleActivationService::class)->setActive($code, 'franchise', $franchiseId, true);
        }
    }

    public function test_all_modules_table_totals_every_module_and_splits_cash_from_online(): void
    {
        $booking = $this->makeBookingScenario('completed')['booking'];
        $booking->update(['price_final' => 500, 'payment_method' => 'online']);
        $parcel = $this->makeParcelOrderScenario('delivered')['order'];
        $parcel->update(['price_final' => 80, 'payment_method' => 'cash']);
        $this->makeMarketplaceOrderScenario('completed', ['total_amount' => 200]);
        $food = $this->makeMarketplaceOrderScenario('completed', ['total_amount' => 150, 'module' => Modules::FOOD]);
        $this->makeMarketplaceOrderScenario('cancelled', ['total_amount' => 999]);
        $this->switchOn(Modules::PARCEL, Modules::COMMERCE, Modules::FOOD);

        $rows = ModuleSales::summary($this->makeSuperAdmin(), now()->startOfYear());

        $this->assertSame(500.0, $rows['service']['sales']);
        $this->assertSame(80.0, $rows['parcel']['sales']);
        $this->assertSame(80.0, $rows['parcel']['cash']);
        $this->assertSame(200.0, $rows['shop']['sales'], 'a cancelled order is not a sale');
        $this->assertSame(1, $rows['shop']['cancelled']);
        $this->assertSame(150.0, $rows['food']['sales'], 'food is counted under Food, not Shop');
        $this->assertSame(1, $rows['food']['orders']);

        $total = ModuleSales::total($rows);
        $this->assertSame(930.0, $total['sales']);
        $this->assertSame(80.0, $total['cash']);
        $this->assertSame(850.0, $total['online']);
        $this->assertSame(5, $total['orders']);

        Livewire::actingAs($this->makeSuperAdmin())->test(Dashboard::class)
            ->assertSet('tab', 'all')
            ->assertSee('Sales by module')
            ->assertSee('₹930.00')
            ->assertSee("setTab('shop')", false)
            ->assertSee("setTab('food')", false);
    }

    public function test_a_module_shows_only_for_its_permission_and_only_the_viewers_franchise(): void
    {
        $mine = $this->makeParcelOrderScenario('delivered');
        $mine['order']->update(['price_final' => 40]);
        $other = $this->makeParcelOrderScenario('delivered');
        $other['order']->update(['price_final' => 900]);

        $actor = $this->makeUserWithPermission('dashboard.view', 'franchise', $mine['franchise']->id);
        $this->grantPermission($actor, 'parcel_orders.view', 'franchise', $mine['franchise']->id);

        $rows = ModuleSales::summary($actor, now()->startOfYear());

        $this->assertSame(['service', 'parcel'], array_keys($rows), 'no shop, food, taxi, rental or hotel without their permissions');
        $this->assertSame(40.0, $rows['parcel']['sales']);
        $this->assertNull($rows['service']['route'], 'no Bookings link without bookings.view');
    }

    public function test_shop_and_food_tabs_list_their_own_orders(): void
    {
        $shop = $this->makeMarketplaceOrderScenario('pending');
        $food = $this->makeMarketplaceOrderScenario('pending', ['module' => Modules::FOOD]);
        $admin = $this->makeSuperAdmin();
        $this->switchOn(Modules::COMMERCE, Modules::FOOD);

        Livewire::actingAs($admin)->withQueryParams(['tab' => 'shop'])->test(Dashboard::class)
            ->assertSee('Recent Shop Orders')->assertSee($shop['order']->code)->assertDontSee($food['order']->code)
            ->assertSee('Shop Settings');

        Livewire::actingAs($admin)->withQueryParams(['tab' => 'food'])->test(Dashboard::class)
            ->assertSee('Recent Food Orders')->assertSee($food['order']->code)->assertDontSee($shop['order']->code)
            ->assertSee('Food Settings');
    }

    public function test_marketplace_orders_screen_filters_shop_and_food_from_the_menu_links(): void
    {
        $shop = $this->makeMarketplaceOrderScenario('pending');
        $food = $this->makeMarketplaceOrderScenario('pending', ['module' => Modules::FOOD]);
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->withQueryParams(['moduleFilter' => 'food'])->test(MarketplaceOrdersManage::class)
            ->assertSee('Food Orders')->assertSee($food['order']->code)->assertDontSee($shop['order']->code);

        Livewire::actingAs($admin)->withQueryParams(['moduleFilter' => 'shop'])->test(MarketplaceOrdersManage::class)
            ->assertSee('Shop Orders')->assertSee($shop['order']->code)->assertDontSee($food['order']->code);
    }

    public function test_period_today_counts_only_todays_orders(): void
    {
        $old = $this->makeBookingScenario('completed')['booking'];
        $old->forceFill(['created_at' => now()->subDays(3), 'price_final' => 700])->save();
        $new = $this->makeBookingScenario('completed')['booking'];
        $new->update(['price_final' => 100]);

        Livewire::actingAs($this->makeSuperAdmin())->test(Dashboard::class)
            ->call('setPeriod', 'today')
            ->assertSee('All modules · Today')
            ->assertViewHas('salesTotal', fn ($t) => $t['sales'] === 100.0 && $t['orders'] === 1);
    }
}
