<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Customer\LocationPicker;
use App\Livewire\Customer\SearchBar;
use App\Models\Setting;
use App\Models\Zone;
use App\Services\Customer\CustomerLocationContext;
use App\Services\Customer\ShopSearch;
use App\Support\SiteControls;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Support\LiveCity;
use Tests\TestCase;

/** Owner 2026-11-16: one account control, no "Book Now" by default, a separate "Area, City" location box, search across modules. */
class HeaderAccountAndSearchPolishTest extends TestCase
{
    use LiveCity;
    use RefreshDatabase;

    public function test_signed_out_header_has_one_sign_in_trigger_and_the_menu_card_has_the_big_button(): void
    {
        $this->liveCity();
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-account-trigger'));
        $this->assertStringContainsString(route('customer.signup'), $html);
        $this->assertStringContainsString('New customer?', $html);
        // Sign in appears as the trigger label and the menu button only; the old duplicate "Sign in" list row is gone.
        $this->assertStringNotContainsString('data-menu-signin-row', $html);
    }

    public function test_book_now_is_off_by_default_and_super_admin_can_turn_it_on(): void
    {
        $this->liveCity();
        $this->assertFalse(SiteControls::headerBookButton());
        $this->get('/')->assertOk()->assertDontSee('Book Now');

        Setting::set(SiteControls::HEADER_BOOK_BUTTON, '1');
        $this->get('/')->assertOk()->assertSee('Book Now');
    }

    public function test_hover_opening_follows_the_admin_switch(): void
    {
        $this->liveCity();
        $this->assertTrue(SiteControls::headerMenuHover());
        $this->get("/")->assertSee("return true && window.matchMedia", false);

        Setting::set(SiteControls::HEADER_MENU_HOVER, '0');
        $this->get('/')->assertSee('return false && window.matchMedia', false);
    }

    public function test_location_box_shows_short_area_and_city_and_stretches(): void
    {
        $city = $this->liveCity('Visakhapatnam');
        $zone = Zone::firstOrFail();
        $zone->update(['name' => 'Mulapet']);
        app(CustomerLocationContext::class)->setZone($zone->id, ['label' => 'Mulapet', 'address' => 'Mulapet, Visakhapatnam, Andhra Pradesh 530001, India']);

        $this->assertSame('Mulapet, Visakhapatnam', app(CustomerLocationContext::class)->shortPlace());
        Livewire::test(LocationPicker::class)->assertSee('Mulapet, Visakhapatnam')->assertSee('max-w-[20rem]', false);
    }

    public function test_location_box_with_only_a_zone_shows_zone_and_city(): void
    {
        $this->liveCity('Thiruvananthapuram');
        $zone = Zone::firstOrFail();
        app(CustomerLocationContext::class)->setZone($zone->id);

        $this->assertSame('Thiruvananthapuram Zone, Thiruvananthapuram', app(CustomerLocationContext::class)->shortPlace());
    }

    public function test_search_covers_food_events_halls_and_homes_but_only_where_each_is_live(): void
    {
        $this->liveCity();
        // Nothing is switched on in this city, so nothing leaks into the box.
        $more = app(ShopSearch::class)->more('biryani', 3);
        $this->assertSame([], ShopSearch::moreGroups($more));

        Livewire::test(SearchBar::class)->set('term', 'biryani')->assertDontSee('data-testid="search-restaurants"', false);
    }

    public function test_more_groups_turn_rows_into_labelled_groups(): void
    {
        $this->liveCity();
        $groups = ShopSearch::moreGroups([
            'city' => 'nellore',
            'restaurants' => collect([(object) ['name' => 'Spicy Hut', 'cuisines' => 'Biryani', 'slug' => 'spicy-hut']]),
            'events' => collect(), 'halls' => collect(), 'homes' => collect(),
        ]);

        $this->assertCount(1, $groups);
        $this->assertSame('restaurants', $groups[0]['key']);
        $this->assertSame('Restaurants', $groups[0]['label']);
        $this->assertStringEndsWith('/nellore/food/spicy-hut', $groups[0]['rows'][0]['url']);
    }
}
