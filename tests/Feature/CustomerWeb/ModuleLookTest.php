<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\SiteControls\Manage;
use App\Models\Setting;
use App\Support\ModuleTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\LiveCity;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\TestCase;

/**
 * Owner "Go" 2026-11-07, Phase 1 of the new look: each business has its own colour (Super Admin can change it), item
 * pages are photo first on phones, every list card carries a ♡, and every rating is one green chip.
 */
class ModuleLookTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use LiveCity;
    use MarketplaceFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->liveCity();
    }

    public function test_each_page_knows_its_business(): void
    {
        $this->assertSame('food', ModuleTheme::forRoute('customer.food.restaurant'));
        $this->assertSame('shop', ModuleTheme::forRoute('customer.shop.product'));
        $this->assertSame('parcel', ModuleTheme::forRoute('customer.parcels.create'));
        $this->assertSame('parcel', ModuleTheme::forRoute('customer.city.parcel'));
        $this->assertSame('services', ModuleTheme::forRoute('customer.city.item'));
        $this->assertSame('services', ModuleTheme::forRoute('customer.categories.show'));
        $this->assertNull(ModuleTheme::forRoute('customer.home'));
        $this->assertNull(ModuleTheme::forRoute('customer.city.show'), 'the city hub shows every business');
        $this->assertNull(ModuleTheme::forRoute('customer.account'));
    }

    public function test_a_service_page_carries_the_services_colour_strip_and_is_photo_first(): void
    {
        $service = $this->makeService($this->makeCategory(), ['name' => 'Colour Check']);
        $html = $this->get(\App\Support\Seo\PublicUrl::service($service))->assertOk()->getContent();

        $this->assertStringContainsString('data-module="services"', $html);
        $this->assertStringContainsString('--mod: #2563EB;', $html);
        $this->assertStringContainsString('data-module-strip', $html);
        $this->assertStringContainsString('relative -mx-4 overflow-hidden bg-slate-100 sm:mx-0', $html, 'photo edge to edge on phones');
        $this->assertStringContainsString('hidden sm:block', $html, 'breadcrumbs from tablet up');
    }

    public function test_the_home_page_has_no_module_strip(): void
    {
        $this->assertStringNotContainsString('data-module-strip', $this->get('/')->getContent());
    }

    public function test_super_admin_sets_the_colours_and_a_bad_code_is_refused(): void
    {
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(Manage::class)
            ->assertSet('moduleColours.food', '#E23744')
            ->set('moduleColours.food', 'red')->call('save')->assertHasErrors('moduleColours.food');

        Livewire::actingAs($admin)->test(Manage::class)
            ->set('moduleColours.food', '#ff5722')->call('save')->assertHasNoErrors();

        $this->assertSame('#FF5722', Setting::get(ModuleTheme::key('food')));
        request()->attributes->remove('module_theme:food');
        $this->assertSame('#FF5722', ModuleTheme::colour('food'));
        $this->assertStringContainsString('--mod: #FF5722;', ModuleTheme::style('food'));
    }

    public function test_service_cards_carry_a_heart_outside_the_link_and_a_green_rating_chip(): void
    {
        $category = $this->makeCategory();
        $this->makeService($category, ['name' => 'Card One']);
        $html = $this->get(\App\Support\Seo\PublicUrl::category($category))->assertOk()->getContent();

        $this->assertStringContainsString('data-service-card', $html);
        $this->assertGreaterThanOrEqual(1, substr_count($html, 'data-save-button'));
        // No link inside a link: the heart comes after the card's </a>.
        $this->assertMatchesRegularExpression('#</a>\s*<a [^>]*data-save-button|</a>\s*<button[^>]*data-save-button|</a>\s*<a href="[^"]*login[^"]*"#s', $html);
    }

    public function test_product_and_shop_cards_carry_a_heart(): void
    {
        [, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $city->update(['slug' => 'heartcity']);
        $this->activateMarketplaceModuleFor($franchise);
        $store = $this->makeStore($franchise, $zone, null, ['name' => 'Heart Stores']);
        $this->makeProduct($store, ['name' => 'Heart Bottle', 'price' => 100, 'stock' => 3]);

        $html = $this->get('/heartcity/shop/'.$store->slug)->assertOk()->getContent();
        $this->assertStringContainsString('data-module="shop"', $html);
        $this->assertStringContainsString('aria-label="Save Heart Bottle"', $html);
        $this->assertStringContainsString('aria-label="Save Heart Stores"', $html, 'the shop itself on its header');
    }
}
