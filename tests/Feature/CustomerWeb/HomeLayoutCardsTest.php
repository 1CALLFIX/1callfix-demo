<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\HomeSections\Manage;
use App\Models\ActivityLog;
use App\Models\Franchise;
use App\Models\MarketplaceCategory;
use App\Models\Service;
use App\Models\ServiceSubcategory;
use App\Models\Setting;
use App\Models\Zone;
use App\Services\Customer\CustomerLocationContext;
use App\Support\HomeCards;
use App\Support\HomeLayout;
use App\Support\SiteText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\LiveCity;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\TestCase;

/** Owner samples 2026-10-31 (Step B): page order + business cards, every bit Super Admin controlled, English + Telugu. */
class HomeLayoutCardsTest extends TestCase
{
    use BookingFixtureHelpers;
    use LiveCity;
    use MarketplaceFixtureHelpers;
    use ParcelOrderFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    private Franchise $franchise;
    private Zone $zone;
    private $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->liveCity();
        [$this->category, $service] = $this->makeCategoryAndService();
        $this->category->update(['name' => 'Cleaning']);
        foreach (['Sofa Cleaning', 'Bathroom Cleaning'] as $i => $name) {
            $sub = ServiceSubcategory::create(['category_id' => $this->category->id, 'name' => $name,
                'slug' => Str::slug($name).'-'.Str::random(4), 'sort_order' => $i, 'is_active' => true]);
            Service::create(['category_id' => $this->category->id, 'subcategory_id' => $sub->id, 'name' => $name.' basic',
                'slug' => Str::slug($name).'-basic-'.Str::random(4), 'base_price' => 300, 'price_type' => 'fixed',
                'duration_estimate_mins' => 60, 'is_active' => true, 'location_required' => true, 'age_restriction' => false, 'sort_order' => $i]);
        }
        $this->franchise = Franchise::firstOrFail();
        $this->zone = Zone::where('franchise_id', $this->franchise->id)->firstOrFail();
        app(CustomerLocationContext::class)->setZone($this->zone->id);
    }

    private function cardsOrder(string $html): array
    {
        preg_match_all('/data-card="([a-z0-9_]+)"/', $html, $m);

        return $m[1];
    }

    private function sectionPos(string $html, string $needle): int
    {
        $pos = strpos($html, $needle);
        $this->assertNotFalse($pos, "missing {$needle}");

        return $pos;
    }

    /** The 2026-11-05 migration saves an order; these tests cover a database where none was ever saved. */
    private function forgetSavedOrder(): void
    {
        Setting::clear(HomeLayout::ORDER, 'global', null);
        request()->attributes->replace([]);
    }

    public function test_the_order_follows_the_older_settings_until_the_super_admin_saves_one(): void
    {
        $this->forgetSavedOrder();
        $this->assertFalse(HomeLayout::hasSavedOrder());
        $this->assertSame(['doors', 'categories', 'hero_banner', 'parcel_card', 'module_cards'], array_slice(HomeLayout::order(), 0, 5));

        Setting::set('home.doors.position', 'after_categories');
        Setting::set(\App\Support\SiteControls::HOME_CATEGORIES_FIRST, '0');
        request()->attributes->replace([]);
        $this->assertSame(['hero_banner', 'categories', 'doors', 'parcel_card'], array_slice(HomeLayout::order(), 0, 4));
    }

    public function test_a_category_card_shows_its_subcategories_with_view_all_and_an_offer_line_in_both_languages(): void
    {
        $html = $this->get('/')->assertOk()->assertSee('data-testid="home-cards"', false)
            ->assertSee('Sofa Cleaning')->assertSee('Bathroom Cleaning')->assertSee('View all')
            ->assertSee('?sub=', false)->assertSee('New')->getContent();
        $this->assertSame(['category_'.$this->category->id], $this->cardsOrder($html));
        $this->assertStringNotContainsString('data-testid="card-line"', $html);

        $this->actingAs($this->makeSuperAdmin());
        Livewire::test(Manage::class)
            ->set('cardLineEn.category_'.$this->category->id, 'Deep clean from ₹299')
            ->set('cardLineTe.category_'.$this->category->id, '₹299 నుండి డీప్ క్లీన్')
            ->set('cardNewDays', '0')
            ->call('save')->assertSee('Saved. The homepage shows it now.');
        $this->assertTrue(ActivityLog::where('description', 'Setting home.card.category_'.$this->category->id.'.line.en changed')->exists());
        auth()->logout();

        $this->get('/')->assertOk()->assertSee('Deep clean from ₹299')->assertSee('More')
            ->assertDontSee('>New<', false);

        Setting::set(SiteText::TELUGU_ENABLED, '1');
        $this->withCookie(SiteText::COOKIE, 'te')->get('/')->assertOk()
            ->assertSee('₹299 నుండి డీప్ క్లీన్')->assertSee('అన్నీ చూడండి')->assertSee('మరిన్ని');
    }

    public function test_parcel_and_shop_cards_only_where_live_with_real_lines_and_the_admin_order(): void
    {
        $html = $this->get('/')->getContent();
        $this->assertSame(['category_'.$this->category->id], $this->cardsOrder($html));

        $this->activateParcelFor($this->franchise);
        $this->activateMarketplaceModuleFor($this->franchise);
        $store = $this->makeStore($this->franchise, $this->zone, null, ['name' => 'Ravi Kirana', 'is_open' => true]);
        $grocery = MarketplaceCategory::create(['module' => 'commerce', 'name' => 'Grocery', 'slug' => 'grocery', 'position' => 1, 'is_active' => true]);
        $rice = MarketplaceCategory::create(['module' => 'commerce', 'parent_id' => $grocery->id, 'name' => 'Rice', 'slug' => 'rice', 'position' => 1, 'is_active' => true]);
        MarketplaceCategory::create(['module' => 'commerce', 'name' => 'Toys', 'slug' => 'toys', 'position' => 2, 'is_active' => true]);
        $this->makeProduct($store, ['name' => 'Sona Rice', 'marketplace_category_id' => $rice->id]);

        // Owner 2026-11-05 (evening): the Parcel card is back, after the services and shop cards.
        $html = $this->get('/')->assertOk()->assertSee('Parcel &amp; delivery', false)->assertSee('Send now')->assertSee('My parcels')
            ->assertSee('Shop near you')->assertSee('Grocery')->assertDontSee('Toys')
            ->assertSee('1 shop open now, order in a tap')->assertSee('category=grocery', false)->getContent();
        $this->assertSame(['category_'.$this->category->id, 'shop', 'parcel'], $this->cardsOrder($html));

        Setting::set(HomeCards::orderKey('shop'), '1');
        Setting::set(HomeCards::showKey('parcel'), '0');
        $this->assertSame(['shop', 'category_'.$this->category->id], $this->cardsOrder($this->get('/')->getContent()));
    }

    public function test_the_shop_card_shows_the_shops_when_products_have_no_shop_category(): void
    {
        $this->activateMarketplaceModuleFor($this->franchise);
        $store = $this->makeStore($this->franchise, $this->zone, null, ['name' => 'Ravi Kirana', 'slug' => 'ravi-kirana', 'is_open' => true]);
        $this->makeProduct($store, ['name' => 'Sona Rice', 'marketplace_category_id' => null]);

        $html = $this->get('/')->assertOk()->assertSee('Shop near you')->assertSee('Ravi Kirana')
            ->assertSee('/shop/ravi-kirana', false)->getContent();
        $this->assertContains('shop', $this->cardsOrder($html));
    }

    public function test_a_food_card_shows_restaurants_with_a_menu_and_a_licence_only(): void
    {
        $this->activateMarketplaceModuleFor($this->franchise, \App\Support\Modules::FOOD);
        $food = fn (string $name, array $o = []) => $this->makeStore($this->franchise, $this->zone, null, array_merge([
            'module' => \App\Support\Modules::FOOD, 'name' => $name, 'slug' => Str::slug($name), 'fssai_number' => '12345678901234', 'is_open' => true,
        ], $o));
        $this->makeProduct($food('Annapurna Mess'), ['name' => 'Veg Meals']);
        $this->makeProduct($food('No Licence Kitchen', ['fssai_number' => null]), ['name' => 'Dosa']);
        $food('Empty Kitchen');

        $html = $this->get('/')->assertOk()->assertSee('Food near you')->assertSee('Annapurna Mess')
            ->assertSee('/food/annapurna-mess', false)->assertDontSee('No Licence Kitchen')->assertDontSee('Empty Kitchen')->getContent();
        $this->assertContains('food', $this->cardsOrder($html));

        Setting::set(HomeCards::showKey('food'), '0');
        request()->attributes->replace([]);
        $this->assertNotContains('food', $this->cardsOrder($this->get('/')->getContent()));
    }

    /** Owner 2026-11-12: "here in this section hotel is missing". */
    public function test_a_hotels_card_shows_bookable_hotels_where_hotels_is_on(): void
    {
        \App\Models\Module::where('code', \App\Support\Modules::HOTEL)->update(['is_implemented' => true]);
        $type = \App\Models\AccommodationType::firstOrCreate(['slug' => 'hotel'], ['name' => 'Hotel', 'is_active' => true]);
        $owner = \App\Models\Provider::create([
            'user_id' => \App\Models\User::create(['uuid' => (string) Str::uuid(), 'name' => 'Owner', 'phone' => '9'.fake()->numerify('#########'), 'role' => 'provider', 'status' => 'active'])->id,
            'franchise_id' => $this->franchise->id, 'zone_id' => $this->zone->id, 'provider_type' => 'independent', 'kyc_status' => 'approved', 'is_active' => true,
        ]);
        $hotel = fn (string $name, string $status) => \App\Models\Accommodation::create([
            'provider_id' => $owner->id, 'accommodation_type_id' => $type->id, 'franchise_id' => $this->franchise->id, 'zone_id' => $this->zone->id,
            'name' => $name, 'address_line' => 'Road', 'lat' => 1, 'lng' => 1, 'is_active' => true, 'approval_status' => $status,
        ]);
        $hotel('Sea View Lodge', 'approved');
        $hotel('Pending Inn', 'pending');

        $this->assertNotContains('hotels', $this->cardsOrder($this->get('/')->getContent()), 'Hotels off: no card.');

        app(\App\Services\ModuleActivationService::class)->setActive(\App\Support\Modules::HOTEL, 'franchise', $this->franchise->id, true);
        request()->attributes->replace([]);
        $html = $this->get('/')->assertOk()->assertSee('Hotels near you')->assertSee('Sea View Lodge')
            ->assertSee('/hotels/sea-view-lodge', false)->assertDontSee('Pending Inn')->assertSee('1 hotel to book, in 3 taps')->getContent();
        $this->assertContains('hotels', $this->cardsOrder($html));
    }

    public function test_the_2026_11_05_order_puts_the_banner_first_and_the_business_cards_after_most_booked_and_offers(): void
    {
        $migration = require database_path('migrations/2026_11_05_100000_home_order_modules_after_offers.php');
        $migration->up();
        request()->attributes->replace([]);

        $this->assertSame(['hero_banner', 'doors', 'most_booked', 'offers', 'module_cards', 'categories'], array_slice(HomeLayout::order(), 0, 6));
        $this->assertFalse(HomeCards::cardShown('parcel'));
        $this->assertNotContains('parcel_card', HomeLayout::visible());
        $this->assertSame('Best offers near you', SiteText::get('home.offers.title'));
    }

    public function test_the_evening_2026_11_05_order_puts_the_doors_first_and_brings_the_parcel_card_back(): void
    {
        foreach (['2026_11_05_100000_home_order_modules_after_offers', '2026_11_05_120000_home_doors_first_parcel_card_back'] as $file) {
            (require database_path("migrations/{$file}.php"))->up();
        }
        request()->attributes->replace([]);

        $this->assertSame(['doors', 'hero_banner', 'most_booked', 'offers', 'module_cards'], array_slice(HomeLayout::order(), 0, 5));
        $this->assertTrue(HomeCards::cardShown('parcel'));
        $this->assertSame(39, HomeCards::cardOrder('parcel'));
        $this->assertGreaterThan(HomeCards::cardOrder('food'), HomeCards::cardOrder('parcel'));
    }

    public function test_the_super_admin_edits_the_explore_line_in_both_languages_and_switches_off_the_moving_row(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $page = Livewire::test(Manage::class)
            ->assertSet('promiseEn', SiteText::TEXTS['home.promise'][2])
            ->assertSet('newAutoscroll', true)
            ->set('promiseEn', 'Pros, shops, food and parcels in Nellore.')
            ->set('promiseTe', 'నెల్లూరులో అన్నీ.')
            ->set('newAutoscroll', false)
            ->call('save')->assertHasNoErrors();

        request()->attributes->replace([]);
        $this->assertSame('Pros, shops, food and parcels in Nellore.', SiteText::saved('home.promise', 'en'));
        $this->assertSame('నెల్లూరులో అన్నీ.', SiteText::saved('home.promise', 'te'));
        $this->assertFalse(HomeLayout::newAutoscroll());

        // Back to the built-in words: nothing saved.
        $page->set('promiseEn', '')->set('promiseTe', SiteText::TEXTS['home.promise'][3])->call('save');
        request()->attributes->replace([]);
        $this->assertNull(SiteText::saved('home.promise', 'en'));
        $this->assertNull(SiteText::saved('home.promise', 'te'));

        $this->actingAs($this->makeUserWithNoPermissions());
        Livewire::test(Manage::class)->assertForbidden();
    }

    public function test_the_super_admin_moves_and_hides_sections_and_only_a_super_admin_can(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        $page = Livewire::test(Manage::class);
        // Move the business cards to the very top.
        foreach (range(1, 4) as $_) {
            $page->call('moveUp', 'module_cards');
        }
        $page->set('sectionShow.trust', false)->call('save')->assertSee('Saved. The homepage shows it now.');
        $this->assertTrue(HomeLayout::hasSavedOrder());
        $this->assertSame('module_cards', explode(',', Setting::get(HomeLayout::ORDER))[0]);
        $this->assertTrue(ActivityLog::where('description', 'Setting '.HomeLayout::ORDER.' changed')->exists());
        auth()->logout();

        $html = $this->get('/')->assertOk()->assertDontSee('trust-heading', false)->getContent();
        $this->assertLessThan($this->sectionPos($html, 'data-testid="home-doors"'), $this->sectionPos($html, 'data-testid="home-cards"'));

        Setting::set(HomeLayout::showKey('module_cards'), '0');
        $this->get('/')->assertOk()->assertDontSee('data-testid="home-cards"', false);

        $this->actingAs($this->makeUserWithNoPermissions());
        Livewire::test(Manage::class)->assertForbidden();
    }

    public function test_saving_without_reordering_keeps_the_older_settings_in_charge(): void
    {
        $this->forgetSavedOrder();
        $this->actingAs($this->makeSuperAdmin());
        Livewire::test(Manage::class)->call('save');
        $this->assertFalse(HomeLayout::hasSavedOrder());
    }

    public function test_the_parcel_schedule_icon_opens_the_form_on_schedule(): void
    {
        $this->activateParcelFor($this->franchise);
        $this->actingAs($this->makeUserWithNoPermissions());
        Livewire::withQueryParams(['when' => 'scheduled'])->test(\App\Livewire\Customer\Parcel\Create::class)->assertSet('deliveryType', 'scheduled');
        Livewire::withQueryParams(['when' => 'bogus'])->test(\App\Livewire\Customer\Parcel\Create::class)->assertSet('deliveryType', 'now');
    }
}
