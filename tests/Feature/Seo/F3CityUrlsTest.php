<?php

namespace Tests\Feature\Seo;

use App\Actions\CreateBookingAction;
use App\Http\Middleware\CaptureAcquisition;
use App\Models\Address;
use App\Models\Booking;
use App\Models\City;
use App\Models\ContentPage;
use App\Models\Franchise;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\SlugRedirect;
use App\Models\Zone;
use App\Services\Slug\SlugManager;
use App\Support\Seo\PublicUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\LiveCity;
use Tests\Feature\Support\WithLegacyWalletPayments;
use Tests\TestCase;

/**
 * F3 - the public catalog lives at /{city} and /{city}/{slug}; every old shape 301s to it with the query kept.
 */
class F3CityUrlsTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use LiveCity;
    use RbacTestHelpers;
    use RefreshDatabase;
    use WithLegacyWalletPayments;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        // These tests are about routing, not coverage: index every live page.
        \App\Models\Setting::set('seo.min_providers_to_index', '0');
    }

    private function category(string $name = 'AC Repair', array $extra = []): ServiceCategory
    {
        return $this->makeCategory(['name' => $name, 'slug' => null] + $extra);
    }

    // ---------------------------------------------------------------- pages

    public function test_city_page_category_page_and_service_page_render_under_the_city_prefix(): void
    {
        $this->liveCity('Nellore');
        $category = $this->category('AC Repair');
        $service = $this->makeService($category, ['name' => 'Gas Refill', 'slug' => null]);

        $this->assertSame('ac-repair', $category->slug);
        $this->assertSame('gas-refill', $service->slug);

        $this->get('/nellore')->assertOk()->assertSeeText('Home services in Nellore')->assertSeeText('AC Repair');
        $this->get('/nellore/ac-repair')->assertOk()->assertSeeText('AC Repair');
        $this->get('/nellore/gas-refill')->assertOk()->assertSeeText('Gas Refill');
    }

    public function test_generated_links_use_the_city_prefix(): void
    {
        $city = $this->liveCity('Nellore');
        $category = $this->category('AC Repair');
        $service = $this->makeService($category, ['slug' => null, 'name' => 'Gas Refill']);

        $this->assertSame(url('/nellore/ac-repair'), PublicUrl::category($category));
        $this->assertSame(url('/nellore/gas-refill'), PublicUrl::service($service));
        $this->assertSame(url('/nellore'), PublicUrl::city($city));
        $this->assertSame(url('/nellore/ac-repair?sub=3'), PublicUrl::category($category, null, ['sub' => 3]));

        $this->get('/')->assertOk()->assertSee(url('/nellore/ac-repair'), false);
    }

    public function test_a_bare_catalog_slug_is_not_a_public_page(): void
    {
        $this->liveCity('Nellore');
        $this->category('AC Repair');

        $this->get('/ac-repair')->assertNotFound();
    }

    public function test_unknown_first_segments_still_reach_the_cms_fallback_and_404(): void
    {
        $this->liveCity('Nellore');
        ContentPage::create(['slug' => 'franchise', 'title' => 'Franchise', 'content' => 'x', 'is_active' => true]);

        $this->get('/franchise')->assertOk()->assertSeeText('Franchise');
        $this->get('/no-such-thing')->assertNotFound();
        $this->get('/no-such/thing')->assertNotFound();
        $this->get('/nellore/no-such-item')->assertNotFound();
        $this->get('/up')->assertOk();
        $this->get('/login')->assertOk();
    }

    public function test_a_get_on_a_post_only_route_still_answers_405(): void
    {
        $this->liveCity('Nellore');

        $this->get('/logout')->assertStatus(405);
    }

    // ---------------------------------------------------------------- live city rule

    public function test_inactive_city_has_no_public_pages(): void
    {
        $city = $this->liveCity('Guntur');
        $category = $this->category('AC Repair');
        $city->update(['is_active' => false]);

        $this->get('/guntur')->assertNotFound();
        $this->get('/guntur/ac-repair')->assertNotFound();
    }

    public function test_city_without_an_active_franchise_has_no_public_pages(): void
    {
        $city = $this->liveCity('Kurnool');
        $this->category('AC Repair');
        Franchise::where('city_id', $city->id)->update(['status' => 'inactive']);

        $this->get('/kurnool')->assertNotFound();
        $this->get('/kurnool/ac-repair')->assertNotFound();
    }

    public function test_an_inactive_item_is_a_404_even_in_a_live_city(): void
    {
        $this->liveCity('Nellore');
        $category = $this->category('AC Repair');
        $this->makeService($category, ['slug' => 'old-service', 'is_active' => false]);

        $this->get('/nellore/old-service')->assertNotFound();
    }

    // ---------------------------------------------------------------- legacy shims

    public function test_legacy_category_url_301s_to_the_city_url_with_the_query_string_kept(): void
    {
        $this->liveCity('Nellore');
        $category = $this->category('AC Repair');

        $this->get('/categories/'.$category->slug.'?utm_source=meta&utm_medium=paid&utm_campaign=ac_oct26&z=%20a')
            ->assertStatus(301)
            ->assertRedirect(url('/nellore/ac-repair').'?utm_source=meta&utm_medium=paid&utm_campaign=ac_oct26&z=%20a');
    }

    public function test_legacy_service_urls_by_id_and_slug_301(): void
    {
        $this->liveCity('Nellore');
        $service = $this->makeService($this->category('AC Repair'), ['slug' => null, 'name' => 'Gas Refill']);

        $this->get('/services/'.$service->id.'?utm_source=g')->assertStatus(301)->assertRedirect(url('/nellore/gas-refill').'?utm_source=g');
        $this->get('/services/gas-refill')->assertStatus(301)->assertRedirect(url('/nellore/gas-refill'));
        $this->get('/services/999999')->assertNotFound();
        $this->get('/categories/nope')->assertNotFound();
    }

    public function test_with_several_live_cities_a_legacy_url_goes_through_the_chooser_and_keeps_utm(): void
    {
        $this->liveCity('Nellore');
        $this->liveCity('Guntur');
        $category = $this->category('AC Repair');

        $this->get('/categories/'.$category->slug.'?utm_source=meta')
            ->assertStatus(301)
            ->assertRedirect(route('customer.city.choose', ['to' => 'ac-repair']).'&utm_source=meta');

        $html = $this->get('/choose-city?to=ac-repair&utm_source=meta')->assertOk()->getContent();
        $this->assertStringContainsString('/nellore/ac-repair?utm_source=meta', $html);
        $this->assertStringContainsString('/guntur/ac-repair?utm_source=meta', $html);
    }

    public function test_the_chooser_skips_itself_when_only_one_city_is_live(): void
    {
        $this->liveCity('Nellore');

        $this->get('/choose-city?to=ac-repair&utm_source=meta')->assertRedirect(url('/nellore/ac-repair').'?utm_source=meta');
    }

    public function test_the_visitors_own_city_wins_over_the_chooser(): void
    {
        $this->liveCity('Nellore');
        $guntur = $this->liveCity('Guntur');
        $category = $this->category('AC Repair');
        $zone = Zone::where('franchise_id', Franchise::where('city_id', $guntur->id)->value('id'))->first();

        $this->withSession([\App\Services\Customer\CustomerLocationContext::SESSION_KEY => $zone->id])
            ->get('/categories/'.$category->slug)
            ->assertStatus(301)
            ->assertRedirect(url('/guntur/ac-repair'));
    }

    public function test_visiting_a_city_url_points_the_session_zone_at_that_city(): void
    {
        $nellore = $this->liveCity('Nellore');
        $guntur = $this->liveCity('Guntur');
        $this->category('AC Repair');

        $this->get('/guntur/ac-repair')->assertOk();

        $zone = app(\App\Services\Customer\CustomerLocationContext::class)->zone();
        $this->assertSame($guntur->id, $zone->franchise->city_id);
        $this->assertNotSame($nellore->id, $zone->franchise->city_id);
    }

    // ---------------------------------------------------------------- redirects after slug changes

    public function test_old_category_slug_301s_to_the_current_url_in_one_hop_even_after_two_renames(): void
    {
        $this->liveCity('Nellore');
        $admin = $this->makeSuperAdmin();
        $category = $this->category('AC Repair');

        SlugManager::change($category, 'ac-service', $admin);
        SlugManager::change($category->refresh(), 'cooling', $admin);

        $this->get('/nellore/ac-repair?utm_source=x')->assertStatus(301)->assertRedirect(url('/nellore/cooling').'?utm_source=x');
        $this->get('/nellore/ac-service')->assertStatus(301)->assertRedirect(url('/nellore/cooling'));
        $this->get('/nellore/cooling')->assertOk();
        $this->get('/categories/ac-repair')->assertStatus(301)->assertRedirect(url('/nellore/cooling'));
    }

    public function test_old_city_slug_301s_to_the_new_city_url_keeping_the_rest_of_the_path(): void
    {
        $city = $this->liveCity('Nellore');
        $admin = $this->makeSuperAdmin();
        $this->category('AC Repair');

        SlugManager::change($city, 'nellore-city', $admin);

        $this->get('/nellore?utm_source=x')->assertStatus(301)->assertRedirect(url('/nellore-city').'?utm_source=x');
        $this->get('/nellore/ac-repair?utm_source=x')->assertStatus(301)->assertRedirect(url('/nellore-city/ac-repair').'?utm_source=x');
        $this->get('/nellore-city/ac-repair')->assertOk();
    }

    public function test_deactivated_duplicate_service_url_301s_to_the_kept_service(): void
    {
        $this->liveCity('Nellore');
        $category = $this->category('Appliance');
        $kept = $this->makeService($category, ['name' => 'Refrigerator | Fridge Service', 'slug' => null]);
        $dupe = $this->makeService($category, ['name' => 'Refrigerator | Fridge Service', 'slug' => null]);
        $this->assertSame('refrigerator-fridge-service', $kept->slug);
        $this->assertSame('refrigerator-fridge-service-2', $dupe->slug);

        SlugManager::redirectTo($dupe, $kept);

        // While the duplicate is still active its own URL serves it.
        $this->get('/nellore/refrigerator-fridge-service-2')->assertOk();

        $dupe->update(['is_active' => false]);

        $this->get('/nellore/refrigerator-fridge-service-2?utm_source=g')
            ->assertStatus(301)->assertRedirect(url('/nellore/refrigerator-fridge-service').'?utm_source=g');
        $this->get('/services/'.$dupe->id)->assertStatus(301)->assertRedirect(url('/nellore/refrigerator-fridge-service'));
        $this->get('/nellore/refrigerator-fridge-service')->assertOk();
    }

    // ---------------------------------------------------------------- QA rows

    public function test_qa_rows_are_never_public_when_hidden_and_never_in_the_sitemap_or_indexed(): void
    {
        $this->liveCity('Nellore');
        $qaCategory = $this->makeCategory(['name' => '[QA] Plumbing', 'slug' => 'qa-plumbing']);
        $qaService = $this->makeService($qaCategory, ['name' => '[QA] Tap fix', 'slug' => 'qa-tap-fix']);
        $real = $this->category('Real Cat');
        $realInQa = $this->makeService($qaCategory, ['name' => 'Fine name', 'slug' => 'fine-name']);

        // Hiding on (production default): 404 everywhere, including a normal-named service inside a QA category.
        config(['seo.hide_qa_rows' => true]);
        $this->get('/nellore/qa-plumbing')->assertNotFound();
        $this->get('/nellore/qa-tap-fix')->assertNotFound();
        $this->get('/nellore/fine-name')->assertNotFound();
        $this->get('/nellore')->assertOk()->assertDontSeeText('[QA] Plumbing')->assertSeeText('Real Cat');

        // Hiding off (QA / staging): reachable, but NEVER indexable and never in a sitemap.
        config(['seo.hide_qa_rows' => false]);
        $this->get('/nellore/qa-plumbing')->assertOk()->assertSee('noindex', false);
        $this->get('/nellore/qa-tap-fix')->assertOk()->assertSee('noindex', false);

        $xml = $this->get('/sitemap-nellore.xml')->assertOk()->getContent();
        $this->assertStringNotContainsString('qa-', $xml);
        $this->assertStringNotContainsString('fine-name', $xml);
        $this->assertStringContainsString('/nellore/real-cat', $xml);
        $this->assertNotNull($real);
        $this->assertNotNull($qaService);
        $this->assertNotNull($realInQa);
    }

    public function test_a_qa_city_is_hidden_on_production_and_never_in_the_sitemap_index(): void
    {
        $this->liveCity('[QA] City', 'qa-city');
        $this->liveCity('Nellore');
        $this->category('AC Repair');

        config(['seo.hide_qa_rows' => true]);
        $this->get('/qa-city')->assertNotFound();

        config(['seo.hide_qa_rows' => false]);
        Cache::flush();
        $this->get('/sitemap.xml')->assertOk()->assertDontSee('qa-city', false)->assertSee('sitemap-nellore.xml', false);
        $this->get('/sitemap-qa-city.xml')->assertNotFound();
    }

    // ---------------------------------------------------------------- the ad link

    public function test_ad_link_301s_to_the_city_url_keeping_utm_and_a_booking_afterwards_stores_attribution_and_city(): void
    {
        $city = $this->liveCity('Nellore');
        $franchise = Franchise::where('city_id', $city->id)->first();
        $zone = Zone::where('franchise_id', $franchise->id)->first();
        $franchise->update(['code' => 'NLR001']);

        // Production today: a random-suffix category slug.
        // Created without model events: production rows predate the slug helper, which would lower-case this.
        $category = ServiceCategory::withoutEvents(fn () => $this->makeCategory(['name' => 'Appliance | AC Repair', 'slug' => 'appliance-ac-repair-Bs7r']));
        $service = $this->makeService($category, ['name' => 'Gas Refill', 'slug' => null, 'base_price' => 1000]);
        $customer = $this->makeCustomer();
        $address = Address::create([
            'user_id' => $customer->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'label' => 'Home', 'lat' => 1.0, 'lng' => 1.0, 'address_line' => 'Addr',
        ]);

        $query = '?utm_source=meta&utm_medium=paid&utm_campaign=ac_oct26';

        // Before the clean-up the ad link already lands on the city URL of the (still suffixed) slug.
        $this->get('/categories/appliance-ac-repair-Bs7r'.$query)
            ->assertStatus(301)->assertRedirect(url('/nellore/appliance-ac-repair-Bs7r').$query);

        // Owner runs the clean-up, then sets the AC category slug from the admin screen.
        Artisan::call('catalog:clean-slugs', ['--apply' => true]);
        $this->assertSame('appliance-ac-repair', $category->refresh()->slug);
        SlugManager::change($category, 'ac-repair', $this->makeSuperAdmin());

        // The very same ad link, with the same query, 301s to the current category slug.
        Cache::flush();
        $this->flushSession();
        $this->get('/categories/appliance-ac-repair-Bs7r'.$query)
            ->assertStatus(301)->assertRedirect(url('/nellore/ac-repair').$query);

        // Follow it like a browser would; the page renders and the first touch is on the session.
        $this->get('/nellore/ac-repair'.$query)->assertOk();
        $acq = session(CaptureAcquisition::SESSION_KEY);
        $this->assertSame('meta', $acq['utm_source']);
        $this->assertSame('paid', $acq['utm_medium']);
        $this->assertSame('ac_oct26', $acq['utm_campaign']);
        $this->assertSame('nellore', $acq['city']);

        // A booking afterwards stores all of it.
        $booking = app(CreateBookingAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
            'service_id' => $service->id, 'address_id' => $address->id, 'payment_method' => 'cash',
        ]);

        $stored = Booking::findOrFail($booking->id)->acquisition;
        $this->assertSame('meta', $stored['utm_source']);
        $this->assertSame('paid', $stored['utm_medium']);
        $this->assertSame('ac_oct26', $stored['utm_campaign']);
        $this->assertSame('nellore', $stored['city']);
    }

    public function test_the_city_key_is_stamped_when_the_first_touch_page_is_the_legacy_redirect_itself(): void
    {
        $this->liveCity('Nellore');
        $category = $this->category('AC Repair');

        $this->get('/categories/'.$category->slug.'?utm_source=meta')->assertStatus(301);

        $this->assertSame('nellore', session(CaptureAcquisition::SESSION_KEY)['city']);
    }

    public function test_a_city_alone_is_not_attribution(): void
    {
        $this->liveCity('Nellore');
        $this->category('AC Repair');

        $this->get('/nellore/ac-repair')->assertOk();

        $this->assertNull(session(CaptureAcquisition::SESSION_KEY));
    }

    public function test_slug_redirect_rows_are_kept_as_item_references(): void
    {
        $this->liveCity('Nellore');
        $category = $this->category('AC Repair');
        SlugManager::change($category, 'cooling', $this->makeSuperAdmin());

        $row = SlugRedirect::where('old_slug', 'ac-repair')->firstOrFail();
        $this->assertSame(['catalog', 'category', $category->id], [$row->scope, $row->target_type, (int) $row->target_id]);
        $this->assertNull(City::where('slug', 'ac-repair')->first());
        $this->assertNull(Service::where('slug', 'ac-repair')->first());
    }
}
