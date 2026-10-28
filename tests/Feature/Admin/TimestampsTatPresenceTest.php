<?php

namespace Tests\Feature\Admin;

use App\Livewire\Bookings\Index as BookingsIndex;
use App\Livewire\Customers\Index as CustomersIndex;
use App\Livewire\Dashboard;
use App\Models\BookingStatusHistory;
use App\Models\User;
use App\Support\Turnaround;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/** Owner 2026-10-28: full created / completed date-time + TAT on every transaction list, Online on every people list. */
class TimestampsTatPresenceTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    public function test_turnaround_formats_seconds_to_days(): void
    {
        $this->assertSame('—', Turnaround::format(null));
        $this->assertSame('45s', Turnaround::format(45));
        $this->assertSame('12m', Turnaround::format(12 * 60));
        $this->assertSame('3h 05m', Turnaround::format(3 * 3600 + 5 * 60));
        $this->assertSame('2d 4h', Turnaround::format(2 * 86400 + 4 * 3600 + 59));
    }

    public function test_close_uses_done_column_then_status_history_and_marks_open_items(): void
    {
        $booking = $this->makeBookingScenario('completed')['booking'];
        $booking->forceFill(['completed_at' => now()->subHour()])->save();
        $close = Turnaround::close($booking->fresh(), 'completed_at');
        $this->assertSame('completed', $close['outcome']);
        $this->assertFalse($close['open']);

        $cancelled = $this->makeBookingScenario('cancelled')['booking'];
        $at = now()->subMinutes(30)->startOfSecond();
        BookingStatusHistory::create(['booking_id' => $cancelled->id, 'status' => 'cancelled', 'changed_at' => $at]);
        $close = Turnaround::close($cancelled->fresh()->load(['statusHistory' => Turnaround::historyScope()]), 'completed_at');
        $this->assertSame('cancelled', $close['outcome']);
        $this->assertTrue($close['at']->equalTo($at));

        $open = Turnaround::close($this->makeBookingScenario('searching_provider')['booking'], 'completed_at');
        $this->assertTrue($open['open']);
        $this->assertNull($open['at']);
    }

    public function test_tat_pill_shows_duration_and_running_time(): void
    {
        $from = now()->subHours(3)->subMinutes(5);
        $this->assertStringContainsString('3h 05m', Blade::render('<x-ui.tat :from="$f" :to="$t" />', ['f' => $from, 't' => now()]));
        $this->assertStringContainsString('so far', Blade::render('<x-ui.tat :from="$f" :open="true" />', ['f' => $from]));
    }

    public function test_bookings_list_and_dashboard_show_created_completed_and_tat(): void
    {
        $booking = $this->makeBookingScenario('completed')['booking'];
        $booking->forceFill(['created_at' => now()->subHours(2), 'completed_at' => now()])->save();
        $this->actingAs($this->makeSuperAdmin());

        Livewire::test(BookingsIndex::class)->assertSee('Completed')->assertSee('TAT')->assertSee('2h 00m');
        Livewire::test(Dashboard::class)->assertSee('Recent Bookings')->assertSee('TAT')->assertSee('Parcel — Today')->assertDontSee('Other Verticals');
    }

    public function test_activity_is_recorded_once_a_minute_and_drives_online_column(): void
    {
        $customer = $this->makeCustomer();
        $this->assertFalse($customer->isOnline());

        $this->actingAs($customer)->get('/');
        $seen = $customer->fresh()->last_seen_at;
        $this->assertNotNull($seen);
        $this->assertTrue($customer->fresh()->isOnline());

        $this->travel(30)->seconds();
        $this->actingAs($customer->fresh())->get('/');
        $this->assertTrue($customer->fresh()->last_seen_at->equalTo($seen), 'written at most once a minute');

        $this->travel(User::ONLINE_WINDOW_MINUTES + 1)->minutes();
        $this->assertFalse($customer->fresh()->isOnline());
    }

    public function test_customers_list_shows_online_and_full_joined_time(): void
    {
        $online = $this->makeCustomer();
        $online->forceFill(['last_seen_at' => now()])->save();
        $this->makeCustomer();
        $this->actingAs($this->makeSuperAdmin());

        Livewire::test(CustomersIndex::class)->assertSee('Online')->assertSee('Not seen yet')
            ->assertSee(app(\App\Services\TimezoneResolver::class)->format($online->created_at, null, 'd M Y'));
    }
}
