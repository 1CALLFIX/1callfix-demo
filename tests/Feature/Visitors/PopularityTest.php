<?php

namespace Tests\Feature\Visitors;

use App\Livewire\Insights\LiveVisitors;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\PageView;
use App\Models\Setting;
use App\Services\Visitors\Popularity;
use App\Services\Visitors\VisitTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/** Popularity line under item photos (owner 2026-11-21, Amazon / OLX pattern): real counts only, off until set. */
class PopularityTest extends TestCase
{
    use BookingFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    private function viewers(string $path, int $n, int $minutesAgo = 5): void
    {
        for ($i = 0; $i < $n; $i++) {
            PageView::create(['visitor_id' => bin2hex(random_bytes(16)), 'page_type' => 'item', 'path' => $path, 'channel' => 'web', 'created_at' => now()->subMinutes($minutesAgo)]);
        }
    }

    private function lines(string $item, string $path): array
    {
        return array_column((new Popularity)->lines($item, $path), 'text', 'kind');
    }

    public function test_nothing_shows_until_super_admin_sets_a_minimum(): void
    {
        $this->viewers('/vtown/services/ac-repair', 30);

        $this->assertSame([], $this->lines('service:1', '/vtown/services/ac-repair'));
    }

    public function test_viewers_in_the_past_hour_then_the_past_day_and_never_below_the_minimum(): void
    {
        Setting::set(Popularity::VIEWS_MIN, '3');
        $this->viewers('/vtown/services/ac-repair', 4);
        $this->viewers('/vtown/services/plumbing', 2);
        $this->viewers('/vtown/services/plumbing', 3, 300);
        $this->viewers('/vtown/services/painting', 2);

        $this->assertSame('4 people viewed this in the past hour', $this->lines('service:1', 'vtown/services/ac-repair')['views']);
        $this->assertSame('5 people viewed this in the past 24 hours', $this->lines('service:2', '/vtown/services/plumbing')['views']);
        $this->assertSame([], $this->lines('service:3', '/vtown/services/painting'));
    }

    public function test_one_person_opening_a_page_many_times_counts_once(): void
    {
        Setting::set(Popularity::VIEWS_MIN, '3');
        $vid = bin2hex(random_bytes(16));
        for ($i = 0; $i < 10; $i++) {
            PageView::create(['visitor_id' => $vid, 'page_type' => 'item', 'path' => '/x', 'channel' => 'web', 'created_at' => now()]);
        }

        $this->assertSame([], $this->lines('service:1', '/x'));
    }

    public function test_the_views_line_hides_while_tracking_is_off(): void
    {
        Setting::set(Popularity::VIEWS_MIN, '3');
        Setting::set(VisitTracker::SETTING, '0');
        $this->viewers('/x', 10);

        $this->assertSame([], $this->lines('service:1', '/x'));
    }

    public function test_real_bookings_only_rounded_down(): void
    {
        Setting::set(Popularity::ORDERS_MIN, '10');
        $scenario = $this->makeBookingScenario('completed');
        $make = fn (array $over) => Booking::create(array_merge($scenario['booking']->only([
            'franchise_id', 'zone_id', 'customer_id', 'service_id', 'address_id', 'price_quoted']),
            ['code' => 'TST-'.Str::random(10), 'status' => 'completed', 'payment_status' => 'paid', 'payment_method' => 'cash'], $over));

        for ($i = 0; $i < 11; $i++) {
            $make([]);
        }
        $make(['status' => 'cancelled']);                                                       // cancelled: not counted
        $make(['status' => 'pending', 'payment_status' => 'pending', 'payment_method' => 'online']); // unpaid attempt: not counted
        $make(['created_at' => now()->subDays(40)]);                                             // too old

        $item = 'service:'.$scenario['service']->id;
        $this->assertSame('10+ booked in the past month', $this->lines($item, '/x')['orders']); // 12 real ones → "10+"
        $this->assertArrayNotHasKey('orders', $this->lines('hotel:5', '/x'));
    }

    public function test_rounding_never_goes_up(): void
    {
        $this->assertSame(['7', '10+', '50+', '100+', '1K+', '2K+'],
            array_map([Popularity::class, 'rounded'], [7, 19, 64, 499, 1999, 2500]));
    }

    public function test_super_admin_sets_the_minimums_with_chips_and_it_is_audited(): void
    {
        Livewire::actingAs($this->makeUserWithPermission('visitors.view', 'global'))->test(LiveVisitors::class)
            ->assertDontSee('Show customers how popular')->call('setPopularity', 'views', 5)->assertForbidden();

        Livewire::actingAs($this->makeSuperAdmin())->test(LiveVisitors::class)
            ->assertSee('Show customers how popular')
            ->call('setPopularity', 'views', 5)->assertSee('once it is 5 or more')
            ->call('setPopularity', 'orders', 10)
            ->call('setPopularity', 'orders', 7)->assertStatus(422);

        $this->assertSame(5, Popularity::minimum(Popularity::VIEWS_MIN));
        $this->assertSame(10, Popularity::minimum(Popularity::ORDERS_MIN));
        $this->assertTrue(ActivityLog::where('description', 'Setting popularity.views_min changed')->exists());

        Livewire::actingAs($this->makeSuperAdmin())->test(LiveVisitors::class)->call('setPopularity', 'views', 0);
        $this->assertNull(Popularity::minimum(Popularity::VIEWS_MIN));
    }

    public function test_the_line_renders_under_a_photo_with_its_page_address(): void
    {
        Setting::set(Popularity::VIEWS_MIN, '3');
        $this->viewers('/', 3);

        $this->blade('<x-customer.popularity item="service:1" />')
            ->assertSee('3 people viewed this in the past hour')->assertSee('data-popularity', false);
        $this->blade('<x-customer.popularity item="service:2" />')->assertSee('👁', false);
    }
}
