<?php

namespace Tests\Feature\Visitors;

use App\Livewire\Insights\LiveVisitors;
use App\Models\City;
use App\Models\Franchise;
use App\Models\PageView;
use App\Models\Setting;
use App\Models\User;
use App\Models\VisitorPresence;
use App\Models\Zone;
use App\Services\Customer\CustomerLocationContext;
use App\Services\Visitors\PageClassifier;
use App\Services\Visitors\VisitTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\TestCase;

/** Live Visitors (owner 2026-11-21): the ping, what is and is not counted, the admin screen and its scoping. */
class LiveVisitorsTest extends TestCase
{
    use RbacTestHelpers;
    use RefreshDatabase;

    private const BROWSER = 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/131.0 Mobile Safari/537.36';

    private City $city;

    private Franchise $franchise;

    protected function setUp(): void
    {
        parent::setUp();
        $this->city = $this->makeCity();
        $this->city->forceFill(['slug' => 'vtown'])->save();
        $this->franchise = $this->makeFranchise($this->city);
    }

    private function vid(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function ping(array $data, string $agent = self::BROWSER)
    {
        return $this->withHeader('User-Agent', $agent)->post('/visit-ping', $data + ['k' => 'view']);
    }

    public function test_a_page_view_is_recorded_with_its_kind_module_city_and_source(): void
    {
        $vid = $this->vid();

        $this->ping(['vid' => $vid, 'p' => '/vtown/services/ac-repair', 't' => 'AC Repair in Vtown | 1CallFix', 'r' => 'www.google.com'])->assertNoContent();

        $view = PageView::sole();
        $this->assertSame(['item', 'service', 'customer.city.item', 'AC Repair in Vtown', 'web', 'www.google.com'],
            [$view->page_type, $view->module, $view->route_name, $view->title, $view->channel, $view->referrer_host]);
        $this->assertSame([$this->city->id, $this->franchise->id, $this->city->country_id], [$view->city_id, $view->franchise_id, $view->country_id]);
        $presence = VisitorPresence::findOrFail($vid);
        $this->assertSame(1, $presence->pages);
        $this->assertSame('/vtown/services/ac-repair', $presence->path);
    }

    public function test_more_pages_count_up_the_visit_and_a_still_here_ping_only_moves_last_seen(): void
    {
        $vid = $this->vid();
        $this->ping(['vid' => $vid, 'p' => '/vtown/services']);
        $started = VisitorPresence::findOrFail($vid)->started_at;

        $this->travel(2)->minutes();
        $this->ping(['vid' => $vid, 'p' => '/vtown/food']);
        $this->travel(1)->minutes();
        $this->ping(['vid' => $vid, 'p' => '/vtown/food', 'k' => 'beat']);

        $presence = VisitorPresence::findOrFail($vid);
        $this->assertSame(2, $presence->pages);
        $this->assertSame('/vtown/food', $presence->path);
        $this->assertTrue($presence->started_at->equalTo($started));
        $this->assertTrue($presence->last_seen_at->greaterThan($started->copy()->addMinutes(2)));
        $this->assertSame(2, PageView::count(), 'a beat adds no page view');

        $this->travel(45)->minutes(); // a long gap starts a new visit
        $this->ping(['vid' => $vid, 'p' => '/']);
        $this->assertSame(1, VisitorPresence::findOrFail($vid)->pages);
    }

    public function test_the_customer_app_is_told_apart_from_the_website(): void
    {
        $vid = $this->vid();
        $this->ping(['vid' => $vid, 'p' => '/'], self::BROWSER.' 1CallFixApp/customer');

        $this->assertSame('app', PageView::sole()->channel);
        $this->assertSame('home', PageView::sole()->page_type);
    }

    public function test_bots_staff_partner_apps_admin_pages_bad_ids_and_switched_off_tracking_are_not_counted(): void
    {
        $this->ping(['vid' => $this->vid(), 'p' => '/'], 'Mozilla/5.0 (compatible; Googlebot/2.1)');
        $this->ping(['vid' => $this->vid(), 'p' => '/'], self::BROWSER.' HeadlessChrome/131.0');
        $this->ping(['vid' => $this->vid(), 'p' => '/'], self::BROWSER.' 1CallFixApp/partner');
        $this->ping(['vid' => $this->vid(), 'p' => '/admin/dashboard']);
        $this->ping(['vid' => 'not-an-id', 'p' => '/']);
        $this->actingAs($this->makeSuperAdmin())->ping(['vid' => $this->vid(), 'p' => '/']);
        $this->actingAs($this->makeUserWithPermission('bookings.view', 'global'))->ping(['vid' => $this->vid(), 'p' => '/']);
        $this->assertSame(0, PageView::count());

        Setting::set(VisitTracker::SETTING, '0');
        auth()->logout();
        $this->ping(['vid' => $this->vid(), 'p' => '/'])->assertNoContent();
        $this->assertSame(0, PageView::count());
        $this->assertSame(0, VisitorPresence::count());
    }

    public function test_a_signed_in_customer_is_linked_and_the_area_picked_on_the_site_is_used(): void
    {
        $zone = Zone::create(['franchise_id' => $this->franchise->id, 'name' => 'Vtown Main', 'boundary_polygon' => [['lat' => 1, 'lng' => 1]], 'is_active' => true, 'default_dispatch_radius_km' => 8]);
        $customer = User::create(['uuid' => (string) Str::uuid(), 'name' => 'Ravi Kumar', 'phone' => '9876501234', 'role' => 'customer', 'status' => 'active']);

        $this->actingAs($customer)->withSession([CustomerLocationContext::SESSION_KEY => $zone->id])
            ->ping(['vid' => $this->vid(), 'p' => '/cart']);

        $view = PageView::sole();
        $this->assertSame([$customer->id, 'cart', $zone->id, $this->franchise->id, $this->city->id], [$view->user_id, $view->page_type, $view->zone_id, $view->franchise_id, $view->city_id]);
    }

    public function test_pages_are_sorted_into_kinds_and_modules(): void
    {
        $this->assertSame(['item', 'food'], PageClassifier::classify('customer.food.restaurant'));
        $this->assertSame(['checkout', 'service'], PageClassifier::classify('customer.book'));
        $this->assertSame(['checkout', 'parcel'], PageClassifier::classify('customer.parcels.create'));
        $this->assertSame(['browse', 'listings'], PageClassifier::classify('customer.rent.home'));
        $this->assertSame(['other', null], PageClassifier::classify('customer.help'));
    }

    private function seedVisits(): array
    {
        $other = $this->makeFranchise();
        $customer = User::create(['uuid' => (string) Str::uuid(), 'name' => 'Ravi Kumar', 'phone' => '9876501234', 'role' => 'customer', 'status' => 'active']);
        $here = ['franchise_id' => $this->franchise->id, 'city_id' => $this->city->id, 'country_id' => $this->city->country_id];
        $there = ['franchise_id' => $other->id, 'city_id' => $other->city_id, 'country_id' => $other->country_id];
        [$a, $b, $c] = [$this->vid(), $this->vid(), $this->vid()];
        $row = fn ($vid, $type, $module, $title, $place, $user = null) => PageView::create(['visitor_id' => $vid, 'user_id' => $user, 'page_type' => $type, 'module' => $module,
            'path' => '/vtown/x/'.Str::random(4), 'title' => $title, 'channel' => 'web', 'created_at' => now()] + $place);

        $row($a, 'browse', 'service', 'Services in Vtown', $here, $customer->id);
        $row($a, 'item', 'service', 'AC Repair', $here, $customer->id);
        $row($a, 'checkout', 'service', 'Book AC Repair', $here, $customer->id);
        $row($b, 'item', 'service', 'Plumbing', $here);
        $row($c, 'item', 'food', 'Spice Hut', $there);

        foreach ([[$a, 'checkout', $here, $customer->id, 'app'], [$b, 'item', $here, null, 'web'], [$c, 'item', $there, null, 'web']] as [$vid, $type, $place, $user, $channel]) {
            VisitorPresence::create(['visitor_id' => $vid, 'user_id' => $user, 'page_type' => $type, 'module' => 'service', 'path' => '/vtown', 'title' => 'Page '.$vid,
                'channel' => $channel, 'pages' => 2, 'started_at' => now()->subMinutes(5), 'last_seen_at' => now()] + $place);
        }

        return [$customer, $other];
    }

    public function test_screen_needs_the_permission(): void
    {
        Livewire::actingAs($this->makeUserWithNoPermissions())->test(LiveVisitors::class)->assertForbidden();
    }

    public function test_super_admin_sees_who_is_online_the_funnel_and_who_is_about_to_book(): void
    {
        $this->seedVisits();

        $screen = Livewire::actingAs($this->makeSuperAdmin())->test(LiveVisitors::class)
            ->assertSee('Live Visitors')->assertSee('Ravi Kumar')->assertSee('Book AC Repair')->assertSee('📞 Call', false);

        $online = $screen->viewData('online');
        $this->assertSame([3, 1, 2, 1, 1], [$online['total'], $online['app'], $online['web'], $online['signed_in'], $online['booking']]);
        $funnel = $screen->viewData('funnel');
        $this->assertSame([2, 2, 1], [$funnel['service']['visited'], $funnel['service']['viewed'], $funnel['service']['started']]);
        $this->assertSame(1, $funnel['food']['viewed']);
        $this->assertSame(3, $screen->viewData('totals')['visitors']);
        $this->assertSame('AC Repair', $screen->viewData('topItems')->firstWhere('title', 'AC Repair')?->title);
    }

    public function test_a_franchise_team_sees_only_its_own_city_and_no_names_without_customers_permission(): void
    {
        $this->seedVisits();
        $team = $this->makeUserWithPermission('visitors.view', 'franchise', $this->franchise->id);

        $screen = Livewire::actingAs($team)->test(LiveVisitors::class)
            ->assertDontSee('Ravi Kumar')->assertSee('Signed-in customer')->assertDontSee('📞 Call', false)->assertDontSee('Spice Hut');

        $this->assertSame(2, $screen->viewData('online')['total']);
        $this->assertArrayNotHasKey('food', $screen->viewData('funnel'));

        $this->grantPermission($team, 'customers.view', 'franchise', $this->franchise->id);
        Livewire::actingAs($team->fresh())->test(LiveVisitors::class)->assertSee('Ravi Kumar')->assertSee('📞 Call', false);
    }

    public function test_only_super_admin_switches_tracking_and_it_is_audited(): void
    {
        $team = $this->makeUserWithPermission('visitors.view', 'global');
        Livewire::actingAs($team)->test(LiveVisitors::class)->assertDontSee('Tracking on')->call('toggleTracking')->assertForbidden();

        Livewire::actingAs($this->makeSuperAdmin())->test(LiveVisitors::class)
            ->assertSee('Tracking on')->call('toggleTracking')->assertSee('Visitor tracking is off');

        $this->assertFalse(VisitTracker::enabled());
        $this->assertTrue(\App\Models\ActivityLog::where('description', 'Setting visitors.tracking changed')->exists());
    }

    public function test_old_history_is_pruned(): void
    {
        $old = $this->vid();
        PageView::create(['visitor_id' => $old, 'page_type' => 'home', 'path' => '/', 'channel' => 'web', 'created_at' => now()->subDays(91)]);
        PageView::create(['visitor_id' => $this->vid(), 'page_type' => 'home', 'path' => '/', 'channel' => 'web', 'created_at' => now()->subDays(5)]);
        VisitorPresence::create(['visitor_id' => $old, 'page_type' => 'home', 'path' => '/', 'channel' => 'web', 'started_at' => now()->subDays(3), 'last_seen_at' => now()->subDays(3)]);

        $this->artisan('visitors:prune')->expectsOutput('Deleted 1 old page views and 1 old presence rows.')->assertSuccessful();
        $this->assertSame(1, PageView::count());
    }

    public function test_customer_pages_load_the_ping_script_and_the_menu_links_the_screen(): void
    {
        $this->get('/')->assertOk()->assertSee('js/visit-ping.js', false)->assertSee(route('visit.ping'), false);

        $this->assertTrue(collect(\App\Support\AdminNav::groups()['overview']['items'])->contains('route', 'admin.live-visitors'));
    }
}
