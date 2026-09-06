<?php

namespace Tests\Feature\ProviderWeb;

use App\Models\Booking;
use App\Models\Commission;
use App\Models\DispatchAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * The provider web surfaces printed stored UTC timestamps straight through
 * Carbon::parse(...)->format(...) with no timezone conversion, while the
 * admin booking detail and the whole customer web already route the same
 * columns through TimezoneResolver::format($moment, $franchise, ...). On an
 * Asia/Kolkata platform that is a 5h30m error on the provider's own
 * appointment time, and near midnight it shifts the DATE shown on their
 * earnings.
 *
 * These tests assert the resolved local wall clock appears in the rendered
 * markup and the raw UTC one does not — the same thing the fix guarantees,
 * checked at the surface the provider actually sees.
 */
class ProviderTimezoneDisplayTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    /** A UTC instant whose IST rendering lands on the NEXT day — 21:00 UTC = 02:30 IST. */
    private function crossMidnightUtc(): Carbon
    {
        return Carbon::parse('2026-03-10 21:00:00', 'UTC');
    }

    public function test_offer_list_shows_the_scheduled_time_in_platform_timezone(): void
    {
        $s = $this->makeBookingScenario('searching_provider');
        $s['booking']->update(['scheduled_at' => $this->crossMidnightUtc()]);

        DispatchAttempt::create([
            'booking_id' => $s['booking']->id,
            'provider_id' => $s['provider']->id,
            'status' => 'notified',
            'distance_km' => 2.0,
            'notified_at' => now(),
        ]);

        $response = $this->actingAs($s['provider']->user)->get(route('provider.jobs.index'));

        $response->assertOk();
        // 21:00 UTC on the 10th -> 02:30 on the 11th, IST.
        $response->assertSee('11 Mar, 2:30 AM', false);
        $response->assertDontSee('10 Mar, 9:00 PM', false);
    }

    public function test_job_timeline_shows_status_history_in_platform_timezone(): void
    {
        $s = $this->makeAssignedBookingScenario();
        $s['booking']->statusHistory()->create([
            'status' => 'assigned',
            'changed_by' => $s['provider']->user_id,
            'note' => 'Assigned',
            'changed_at' => $this->crossMidnightUtc(),
        ]);

        $response = $this->actingAs($s['provider']->user)->get(route('provider.jobs.show', $s['booking']));

        $response->assertOk();
        $response->assertSee('11 Mar, 2:30 AM', false);
        $response->assertDontSee('10 Mar, 9:00 PM', false);
    }

    public function test_earnings_row_shows_completion_date_in_platform_timezone(): void
    {
        $s = $this->makeBookingScenario('completed');
        // Cross-midnight the OTHER way: 20:00 UTC Mar 9 = 01:30 IST Mar 10.
        $s['booking']->update([
            'provider_id' => $s['provider']->id,
            'price_final' => 500,
            'completed_at' => Carbon::parse('2026-03-09 20:00:00', 'UTC'),
        ]);

        Commission::create([
            'booking_id' => $s['booking']->id,
            'provider_commission' => 350,
            'franchise_commission' => 50,
            'platform_commission' => 100,
        ]);

        $response = $this->actingAs($s['provider']->user)->get(route('provider.earnings'));

        $response->assertOk();
        $response->assertSee('10 Mar 2026', false);
        $response->assertDontSee('9 Mar 2026', false);
    }

    public function test_activity_feed_renders_without_error_and_uses_local_time(): void
    {
        $s = $this->makeAssignedBookingScenario();
        $s['booking']->statusHistory()->create([
            'status' => 'assigned',
            'changed_by' => $s['provider']->user_id,
            'note' => 'Assigned',
            'changed_at' => $this->crossMidnightUtc(),
        ]);

        $response = $this->actingAs($s['provider']->user)->get(route('provider.activity'));

        $response->assertOk();
        $response->assertSee('11 Mar, 2:30 AM', false);
    }
}
