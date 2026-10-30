<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Customer\SearchBar;
use App\Models\Franchise;
use App\Models\Setting;
use App\Models\Zone;
use App\Support\SiteText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\LiveCity;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\TestCase;

/** One search (owner 2026-10-30): services, shop items and shops in one box; shop results only where the shop is on. */
class SearchShopResultsTest extends TestCase
{
    use BookingFixtureHelpers;
    use LiveCity;
    use MarketplaceFixtureHelpers;
    use RefreshDatabase;

    private Franchise $franchise;

    protected function setUp(): void
    {
        parent::setUp();
        $this->liveCity();
        $this->franchise = Franchise::firstOrFail();
        $zone = Zone::where('franchise_id', $this->franchise->id)->firstOrFail();
        $store = $this->makeStore($this->franchise, $zone, null, ['name' => 'Ganesh Kirana', 'is_open' => true]);
        $this->makeProduct($store, ['name' => 'Ganesh Rice 5kg']);
        $this->makeProduct($store, ['name' => 'Ganesh Hidden Dal', 'is_active' => false]);
        $this->makeProduct($store, ['name' => 'Ganesh Pending Oil', 'is_approved' => false]);
    }

    public function test_no_shop_results_while_the_shop_is_off_here(): void
    {
        Livewire::test(SearchBar::class)->set('term', 'ganesh')
            ->assertDontSee('data-testid="search-products"', false)->assertDontSee('Ganesh Rice 5kg');
    }

    public function test_shop_items_and_shops_show_where_the_shop_is_on_and_only_sellable_items(): void
    {
        $this->activateMarketplaceModuleFor($this->franchise);

        Livewire::test(SearchBar::class)->set('term', 'ganesh')
            ->assertSee('data-testid="search-products"', false)
            ->assertSee('Ganesh Rice 5kg')
            ->assertSee('data-testid="search-shops"', false)
            ->assertSee('Ganesh Kirana')
            ->assertSee('Open now')
            ->assertDontSee('Ganesh Hidden Dal')
            ->assertDontSee('Ganesh Pending Oil')
            ->assertSee('/shop/ganesh-kirana/ganesh-rice-5kg', false);
    }

    public function test_the_results_page_lists_shop_items_and_shops_too(): void
    {
        $this->get(route('customer.search', ['q' => 'ganesh']))->assertOk()->assertDontSee('data-testid="search-page-products"', false);

        $this->activateMarketplaceModuleFor($this->franchise);
        $this->get(route('customer.search', ['q' => 'ganesh']))->assertOk()
            ->assertSee('data-testid="search-page-products"', false)->assertSee('Ganesh Rice 5kg')
            ->assertSee('data-testid="search-page-shops"', false)->assertDontSee('Nothing matched');
    }

    public function test_telugu_labels_and_hint(): void
    {
        $this->activateMarketplaceModuleFor($this->franchise);
        Setting::set(SiteText::TELUGU_ENABLED, '1');
        app()->setLocale('te');

        Livewire::test(SearchBar::class)->assertSee('సేవలు, వస్తువులు, షాపులు వెతకండి…')
            ->set('term', 'ganesh')->assertSee('షాప్ వస్తువులు')->assertSee('షాపులు');
    }
}
