<?php

namespace Tests\Feature\Seo;

use App\Http\Controllers\Customer\CityChooserController;
use App\Livewire\Customer\Home;
use App\Models\Franchise;
use App\Models\Provider;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Models\Zone;
use App\Services\ModuleActivationService;
use App\Services\Slug\SlugManager;
use App\Support\HomeLayout;
use App\Support\Modules;
use App\Support\Seo\CityModules;
use App\Support\Seo\PublicUrl;
use App\Support\Seo\ReservedSlugs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\LiveCity;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\TestCase;

/**
 * City URL architecture (owner brief 2026-11-01):
 *   /{city} hub · /{city}/services · /{city}/services/{slug} · /{city}/shop · /{city}/parcel
 * Old /{city}/{slug} 301s with the query kept; future business words are reserved; only real pages are indexed and
 * listed; test shops are never public (a signed-in test account still sees them for the walk-through).
 */
class CityUrlArchitectureTest extends TestCase
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
        Cache::flush();
    }

    private function franchiseOf($city): Franchise
    {
        return Franchise::where('city_id', $city->id)->firstOrFail();
    }

    private function zoneOf($city): Zone
    {
        return Zone::where('franchise_id', $this->franchiseOf($city)->id)->firstOrFail();
    }

    private function shopOn($city): void
    {
        $this->activateMarketplaceModuleFor($this->franchiseOf($city));
    }

    private function parcelOn($city, ?int $fare = 40): void
    {
        $this->activateMarketplaceModuleFor($this->franchiseOf($city), Modules::PARCEL);
        if ($fare !== null) {
            Setting::set('parcel.base_fare', (string) $fare);
        }
    }

    private function realShop($city, string $name = 'Lakshmi Stores'): Store
    {
        $store = $this->makeStore($this->franchiseOf($city), $this->zoneOf($city), null, ['name' => $name, 'slug' => Str::slug($name)]);
        $this->makeProduct($store, ['name' => 'Steel Bottle', 'slug' => 'steel-bottle']);

        return $store;
    }

    private function testShop($city): Store
    {
        $user = User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Test Seller', 'email' => 'test.seller@1callfix.test',
            'phone' => '9'.fake()->unique()->numerify('#########'), 'role' => 'provider', 'status' => 'active',
        ]);
        $owner = Provider::create([
            'user_id' => $user->id, 'franchise_id' => $this->franchiseOf($city)->id, 'zone_id' => $this->zoneOf($city)->id,
            'provider_type' => 'independent', 'kyc_status' => 'approved', 'is_active' => true,
        ]);
        $store = $this->makeStore($this->franchiseOf($city), $this->zoneOf($city), $owner, ['name' => 'Test Shop', 'slug' => 'test-shop']);
        $this->makeProduct($store, ['name' => 'Test Discount Item', 'slug' => 'test-discount-item']);

        return $store;
    }

    // ---------------------------------------------------------------- the five URLs

    public function test_every_city_gets_the_same_url_shape_with_no_city_hardcoded(): void
    {
        foreach (['Nellore', 'Guntur'] as $name) {
            $city = $this->liveCity($name);
            $category = $this->makeCategory(['name' => 'AC Repair '.$name, 'slug' => null]);
            $slug = $city->slug;

            $this->get('/'.$slug)->assertOk()->assertSeeText('1CallFix in '.$name);
            $this->get('/'.$slug.'/services')->assertOk()->assertSeeText('Home services in '.$name)->assertSeeText($category->name);
            $this->get('/'.$slug.'/services/'.$category->slug)->assertOk()->assertSeeText($category->name);
            $this->assertSame(url('/'.$slug.'/services/'.$category->slug), PublicUrl::category($category, $city));
            $this->assertSame(url('/'.$slug.'/services'), PublicUrl::services($city));
        }
    }

    public function test_the_hub_shows_one_door_per_business_live_in_that_city_only(): void
    {
        $nellore = $this->liveCity('Nellore');
        $guntur = $this->liveCity('Guntur');
        $this->makeCategory(['name' => 'AC Repair', 'slug' => null]);
        $this->makeService(\App\Models\ServiceCategory::firstOrFail(), ['name' => 'Gas Refill', 'slug' => null]);
        $this->shopOn($nellore);
        $this->realShop($nellore);
        $this->parcelOn($nellore);

        $nelloreHub = $this->get('/nellore')->assertOk()->getContent();
        $this->assertStringContainsString('data-door="services"', $nelloreHub);
        $this->assertStringContainsString('data-door="shop"', $nelloreHub);
        $this->assertStringContainsString('data-door="parcel"', $nelloreHub);
        $this->assertStringContainsString(url('/nellore/services'), $nelloreHub);
        $this->assertStringContainsString(url('/nellore/shop'), $nelloreHub);
        $this->assertStringContainsString(url('/nellore/parcel'), $nelloreHub);

        // Commerce and Parcel are on for Nellore's franchise only: Guntur's hub has the services door alone.
        $gunturHub = $this->get('/guntur')->assertOk()->getContent();
        $this->assertStringContainsString('data-door="services"', $gunturHub);
        $this->assertStringNotContainsString('data-door="shop"', $gunturHub);
        $this->assertStringNotContainsString('data-door="parcel"', $gunturHub);
        $this->get('/guntur/shop')->assertNotFound();
        $this->get('/guntur/parcel')->assertNotFound();
        $this->assertNotNull($guntur);
    }

    public function test_the_parcel_page_needs_parcel_on_and_is_indexed_only_with_a_price(): void
    {
        $city = $this->liveCity('Nellore');
        $this->get('/nellore/parcel')->assertNotFound();

        $this->parcelOn($city, null);
        $this->get('/nellore/parcel')->assertOk()->assertSeeText('Parcel delivery in Nellore')
            ->assertSee(route('customer.parcels.create'), false)
            ->assertSee('noindex', false);

        Setting::set('parcel.base_fare', '40');
        $this->get('/nellore/parcel')->assertOk()->assertSeeText('From ₹40')
            ->assertSee('<meta name="robots" content="index, follow">', false)
            ->assertSee('<link rel="canonical" href="https://1callfix.com/nellore/parcel">', false);
    }

    // ---------------------------------------------------------------- old links

    public function test_old_city_item_urls_301_to_the_services_url_keeping_utm_in_one_hop(): void
    {
        $this->liveCity('Nellore');
        $category = $this->makeCategory(['name' => 'AC Repair', 'slug' => null]);
        $service = $this->makeService($category, ['name' => 'Gas Refill', 'slug' => null]);

        $this->get('/nellore/ac-repair?utm_source=meta&gclid=1')->assertStatus(301)
            ->assertRedirect(url('/nellore/services/ac-repair').'?utm_source=meta&gclid=1');
        $this->get('/nellore/gas-refill')->assertStatus(301)->assertRedirect(url('/nellore/services/gas-refill'));

        // An old item slug and an old city slug together: still one hop to the current URL.
        $admin = $this->makeSuperAdmin();
        SlugManager::change($category, 'cooling', $admin);
        SlugManager::change(\App\Models\City::where('slug', 'nellore')->firstOrFail(), 'nellore-city', $admin);
        $this->get('/nellore/ac-repair?utm_source=x')->assertStatus(301)->assertRedirect(url('/nellore-city/services/cooling').'?utm_source=x');
        $this->get('/nellore/services?utm_source=x')->assertStatus(301)->assertRedirect(url('/nellore-city/services').'?utm_source=x');
        $this->assertNotNull($service);
    }

    public function test_categories_list_sends_the_visitor_to_the_city_services_page(): void
    {
        $this->liveCity('Nellore');

        $this->get('/categories?utm_source=g')->assertRedirect(url('/nellore/services').'?utm_source=g');
    }

    // ---------------------------------------------------------------- reserved words

    public function test_future_business_words_are_reserved_and_404_under_a_city(): void
    {
        $this->liveCity('Nellore');

        foreach (['services', 'shop', 'parcel', 'food', 'grocery', 'pharmacy', 'taxi', 'hotels', 'rentals'] as $word) {
            $this->assertTrue(ReservedSlugs::isReserved($word), $word);
        }
        foreach (CityModules::FUTURE_SEGMENTS as $word) {
            $this->get('/nellore/'.$word)->assertNotFound();
        }

        $category = $this->makeCategory(['name' => 'Grocery help', 'slug' => null]);
        $this->assertNotNull(SlugManager::problem($category, 'grocery'));
        $this->assertNotNull(SlugManager::problem(\App\Models\City::firstOrFail(), 'pharmacy'));
    }

    public function test_the_chooser_keeps_the_destination_and_never_leaves_the_city_pages(): void
    {
        $this->assertSame('services', CityChooserController::destination('services'));
        $this->assertSame('shop', CityChooserController::destination('shop'));
        $this->assertSame('parcel', CityChooserController::destination('parcel'));
        $this->assertSame('services/ac-repair', CityChooserController::destination('services/ac-repair'));
        $this->assertSame('services/ac-repair', CityChooserController::destination('ac-repair'), 'an older link carried a bare slug');
        $this->assertSame('', CityChooserController::destination('//evil.example/x'));
        $this->assertSame('', CityChooserController::destination('a/b/c'));

        $this->liveCity('Nellore');
        $this->liveCity('Guntur');
        $html = $this->get('/choose-city?to=shop&utm_source=meta')->assertOk()->getContent();
        $this->assertStringContainsString('/nellore/shop?utm_source=meta', $html);
        $this->assertStringContainsString('/guntur/shop?utm_source=meta', $html);
    }

    // ---------------------------------------------------------------- test shops + indexing

    public function test_a_test_shop_is_never_public_but_a_signed_in_test_account_still_sees_it(): void
    {
        $city = $this->liveCity('Nellore');
        $this->shopOn($city);
        $test = $this->testShop($city);

        // Only the test shop: the shop page is there (Commerce is on) but empty and not indexed.
        $this->get('/nellore/shop')->assertOk()->assertDontSee('Test Discount Item')->assertSee('noindex', false);
        $this->get('/nellore/shop/test-shop')->assertNotFound();
        $this->get('/nellore/shop/test-shop/test-discount-item')->assertNotFound();

        // The walk-through login still sees it, and it is still never indexed.
        $tester = User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Test Customer', 'email' => 'test.customer@1callfix.test',
            'phone' => '9'.fake()->unique()->numerify('#########'), 'role' => 'customer', 'status' => 'active',
        ]);
        $this->actingAs($tester)->get('/nellore/shop')->assertOk()->assertSee('Test Discount Item');
        $this->actingAs($tester)->get('/nellore/shop/test-shop')->assertOk()->assertSee('noindex', false);
        $this->actingAs($tester)->get('/nellore/shop/test-shop/test-discount-item')->assertOk()->assertSee('noindex', false);
        $this->assertTrue($test->isTestShop());
    }

    public function test_a_public_customer_cannot_add_a_test_shop_item_to_the_cart(): void
    {
        $city = $this->liveCity('Nellore');
        $this->shopOn($city);
        $test = $this->testShop($city);
        $customer = $this->makeCustomer();

        Livewire::actingAs($customer)->test(\App\Livewire\Customer\Shop\Home::class, ['city' => 'nellore'])
            ->call('addToCart', $test->products()->firstOrFail()->id)
            ->assertSet('cartMessage', \App\Support\SiteText::get('shop.item_gone'));
        $this->assertSame(0, \App\Models\CartItem::where('user_id', $customer->id)->count());
    }

    public function test_the_sitemap_lists_only_real_city_pages(): void
    {
        $city = $this->liveCity('Nellore');
        $category = $this->makeCategory(['name' => 'AC Repair', 'slug' => null]);
        $this->makeService($category, ['name' => 'Gas Refill', 'slug' => null]);
        $this->providerFor($city, [$category->id]);
        $this->shopOn($city);
        $real = $this->realShop($city);
        $this->testShop($city);
        $this->makeProduct($real, ['name' => 'Hidden Draft', 'slug' => 'hidden-draft', 'is_approved' => false]);

        $xml = $this->get('/sitemap-nellore.xml')->assertOk()->getContent();
        foreach (['/nellore', '/nellore/services', '/nellore/services/ac-repair', '/nellore/services/gas-refill',
            '/nellore/shop', '/nellore/shop/lakshmi-stores', '/nellore/shop/lakshmi-stores/steel-bottle'] as $path) {
            $this->assertStringContainsString('<loc>https://1callfix.com'.$path.'</loc>', $xml, $path);
        }
        $this->assertStringNotContainsString('test-shop', $xml);
        $this->assertStringNotContainsString('hidden-draft', $xml);
        $this->assertStringNotContainsString('/nellore/parcel', $xml, 'parcel is off');
        $this->assertStringNotContainsString('/nellore/ac-repair<', $xml, 'never the old URL');

        // Parcel on with a price: listed.
        $this->parcelOn($city);
        Cache::flush();
        $this->assertStringContainsString('<loc>https://1callfix.com/nellore/parcel</loc>', $this->get('/sitemap-nellore.xml')->getContent());
    }

    public function test_an_inactive_module_has_no_page_and_no_sitemap_entry(): void
    {
        $city = $this->liveCity('Nellore');
        $this->shopOn($city);
        $this->realShop($city);
        app(ModuleActivationService::class)->setActive(Modules::COMMERCE, 'franchise', $this->franchiseOf($city)->id, false);

        $this->get('/nellore/shop')->assertNotFound();
        $this->assertStringNotContainsString('/nellore/shop', $this->get('/sitemap-nellore.xml')->getContent());
        $this->assertStringNotContainsString('data-door="shop"', $this->get('/nellore')->getContent());
    }

    public function test_canonical_tags_point_at_the_new_urls(): void
    {
        $city = $this->liveCity('Nellore');
        $category = $this->makeCategory(['name' => 'AC Repair', 'slug' => null]);
        $this->providerFor($city, [$category->id]);

        foreach (['/nellore', '/nellore/services', '/nellore/services/ac-repair'] as $path) {
            $this->get($path.'?utm_source=g')->assertOk()
                ->assertSee('<link rel="canonical" href="https://1callfix.com'.$path.'">', false);
        }
    }

    public function test_a_city_url_only_scopes_discovery_the_session_zone_follows_the_city(): void
    {
        $this->liveCity('Nellore');
        $guntur = $this->liveCity('Guntur');

        $this->get('/guntur/services')->assertOk();

        $zone = app(\App\Services\Customer\CustomerLocationContext::class)->zone();
        $this->assertSame($guntur->id, $zone->franchise->city_id);
    }

    // ---------------------------------------------------------------- homepage shop rows

    public function test_the_homepage_shows_shop_categories_shops_and_products_where_the_shop_is_on(): void
    {
        $city = $this->liveCity('Nellore');
        $this->shopOn($city);
        $store = $this->realShop($city);
        $cat = $this->makeMarketplaceCategory(['name' => 'Kitchen', 'slug' => 'kitchen', 'module' => Modules::COMMERCE, 'is_active' => true]);
        $store->products()->update(['marketplace_category_id' => $cat->id]);
        $this->testShop($city);

        Livewire::test(Home::class)
            ->assertSee('Shop by category')->assertSee('Kitchen')
            ->assertSee('Shops near you')->assertSee('Lakshmi Stores')
            ->assertSee('From local shops')->assertSee('Steel Bottle')
            ->assertDontSee('Test Discount Item');

        // Each row is the Super Admin's to switch off (Admin → Homepage sections).
        Setting::set(HomeLayout::showKey('shop_products'), '0');
        HomeLayout::forget();
        Livewire::test(Home::class)->assertSee('Lakshmi Stores')->assertDontSee('From local shops');
    }

    public function test_the_homepage_shop_rows_hide_where_the_shop_is_off(): void
    {
        $city = $this->liveCity('Nellore');
        $this->realShop($city);

        Livewire::test(Home::class)->assertDontSee('Shop by category')->assertDontSee('Lakshmi Stores')->assertDontSee('From local shops');
    }

    public function test_the_homepage_add_button_puts_a_real_product_in_the_cart(): void
    {
        $city = $this->liveCity('Nellore');
        $this->shopOn($city);
        $product = $this->realShop($city)->products()->firstOrFail();
        $customer = $this->makeCustomer();

        Livewire::actingAs($customer)->test(Home::class)->call('addToCart', $product->id);

        $this->assertSame(1, (int) \App\Models\CartItem::where('user_id', $customer->id)->where('product_id', $product->id)->value('quantity'));
    }
}
