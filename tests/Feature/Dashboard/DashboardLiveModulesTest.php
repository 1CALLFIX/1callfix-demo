<?php

namespace Tests\Feature\Dashboard;

use App\Livewire\Dashboard;
use App\Livewire\Workers\Index as WorkersIndex;
use App\Livewire\Providers\Index as ProvidersIndex;
use App\Services\ModuleActivationService;
use App\Support\ModuleSales;
use App\Support\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\TestCase;

/**
 * Owner 2026-11-09: the dashboard shows only modules that are built and switched on (not Taxi / Rental / Hotel still
 * to be built), shows the dates the numbers cover and today's date, every card opens something, there is a circle and
 * a 7-day bar chart; and every admin list opens on All (Partners used to open on an empty "Needs review").
 */
class DashboardLiveModulesTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;
    use MarketplaceFixtureHelpers;

    public function test_only_modules_switched_on_somewhere_show(): void
    {
        [, , $franchise] = $this->makeFranchiseTree();
        $this->activateMarketplaceModuleFor($franchise, Modules::FOOD);

        $this->assertSame(['service', 'food'], ModuleSales::liveKeys());

        Livewire::actingAs($this->makeSuperAdmin())->test(Dashboard::class)
            ->assertSee("setTab('food')", false)
            ->assertDontSee("setTab('taxi')", false)->assertDontSee("setTab('rental')", false)->assertDontSee("setTab('hotel')", false)
            ->assertDontSee("setTab('shop')", false)->assertDontSee('pre-launch');

        // Switched on later: it appears by itself.
        $this->activateMarketplaceModuleFor($franchise, Modules::COMMERCE);
        Livewire::actingAs($this->makeSuperAdmin())->test(Dashboard::class)->assertSee("setTab('shop')", false);

        // Switched off everywhere: gone again; an unknown tab falls back to All.
        app(ModuleActivationService::class)->setActive(Modules::FOOD, 'franchise', $franchise->id, false);
        Livewire::actingAs($this->makeSuperAdmin())->withQueryParams(['tab' => 'food'])->test(Dashboard::class)
            ->assertSet('tab', 'all')->assertDontSee("setTab('food')", false);
    }

    /** Owner 2026-11-13: shops, restaurants and hotels each get their own tile, once one has joined. */
    public function test_each_kind_of_business_gets_its_own_tile_once_one_has_joined(): void
    {
        Livewire::actingAs($this->makeSuperAdmin())->test(Dashboard::class)
            ->assertSee('Riders on duty')->assertSee('Partners on duty')
            ->assertDontSee('Shops open')->assertDontSee('Restaurants open')->assertDontSee('Hotels live');

        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $this->makeStore($franchise, $zone, null, ['module' => Modules::FOOD, 'name' => 'Annapurna Mess', 'fssai_number' => '12345678901234'])
            ->forceFill(['approval_status' => 'approved', 'is_active' => true, 'is_open' => true])->save();

        Livewire::actingAs($this->makeSuperAdmin())->test(Dashboard::class)
            ->assertSee('Restaurants open')->assertDontSee('Shops open')
            ->assertSee(route('admin.stores.index', ['moduleFilter' => 'food']), false);
    }

    public function test_the_dashboard_shows_todays_date_the_period_dates_charts_and_links(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-09 10:00', 'Asia/Kolkata'));

        Livewire::actingAs($this->makeSuperAdmin())->test(Dashboard::class)
            ->assertSee('Good morning')
            ->assertSee('Monday, 9 November 2026')
            ->call('setPeriod', 'today')->assertSeeHtml('data-testid="period-dates">Mon, 9 Nov 2026')
            ->call('setPeriod', 'month')->assertSeeHtml('data-testid="period-dates">November 2026')
            ->assertSeeHtml('data-testid="module-donut"')
            ->assertSeeHtml('data-testid="daily-bars"')
            ->assertSeeHtml('data-testid="team-now"')
            ->assertSeeHtml('href="#sales-by-module"')
            ->assertSee(route('admin.payments.index', ['methodFilter' => 'cash']), false)
            ->assertSee(route('admin.partners.index', ['tab' => 'people']), false);
    }

    public function test_the_7_day_chart_counts_orders_per_day_per_module(): void
    {
        $this->makeBookingScenario('completed');
        $rows = ModuleSales::daily($this->makeSuperAdmin(), ['service'], 7);

        $this->assertCount(7, $rows);
        $this->assertSame(1, end($rows)['service'], "today's booking is in today's bar");
    }

    public function test_partner_lists_open_on_all_first(): void
    {
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(WorkersIndex::class, ['embedded' => true])
            ->assertSet('statusFilter', '')
            ->assertViewHas('statusTabs', fn ($tabs) => array_key_first($tabs) === '' && $tabs[''] === 'All partners' && isset($tabs['review']));
        Livewire::actingAs($admin)->test(ProvidersIndex::class, ['embedded' => true])
            ->assertSet('statusFilter', '')
            ->assertViewHas('statusTabs', fn ($tabs) => array_key_first($tabs) === '');
    }
}
