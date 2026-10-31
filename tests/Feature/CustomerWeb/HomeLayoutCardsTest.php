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

    public function test_the_order_follows_the_older_settings_until_the_super_admin_saves_one(): void
    {
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

        $html = $this->get('/')->assertOk()->assertSee('Parcel &amp; delivery', false)->assertSee('Send now')->assertSee('My parcels')
            ->assertSee('Shop near you')->assertSee('Grocery')->assertDontSee('Toys')
            ->assertSee('1 shop open now, order in a tap')->assertSee('category=grocery', false)->getContent();
        $this->assertSame(['category_'.$this->category->id, 'parcel', 'shop'], $this->cardsOrder($html));

        Setting::set(HomeCards::orderKey('shop'), '1');
        Setting::set(HomeCards::showKey('parcel'), '0');
        $this->assertSame(['shop', 'category_'.$this->category->id], $this->cardsOrder($this->get('/')->getContent()));
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
