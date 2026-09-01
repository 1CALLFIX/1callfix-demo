<?php

namespace Tests\Feature\ProviderWeb;

use App\Livewire\Provider\Activity;
use App\Livewire\Provider\Dashboard;
use App\Livewire\Provider\Earnings;
use App\Livewire\Provider\History;
use App\Livewire\Provider\Jobs\Index;
use App\Livewire\Provider\Jobs\Show;
use App\Models\BookingStatusHistory;
use App\Models\Commission;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * PHASE PW1 §7 (earnings / history / activity, read-only) and §9
 * (provider-facing stuck-accepted-job surfaces — read-only, reusing
 * StuckBookingService's thresholds, no mutation).
 */
class ProviderStuckJobAndFeedsTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    private function staleAssignedJob(): array
    {
        $s = $this->makeAssignedBookingScenario();
        // Entered 'assigned' 90 min ago — past the default 60-min threshold.
        BookingStatusHistory::where('booking_id', $s['booking']->id)->delete();
        BookingStatusHistory::create([
            'booking_id' => $s['booking']->id, 'status' => 'assigned',
            'note' => 'Accepted', 'changed_at' => now()->subMinutes(90),
        ]);

        return $s;
    }

    public function test_dashboard_flags_a_stale_accepted_job_without_mutating_it(): void
    {
        $s = $this->staleAssignedJob();
        $this->actingAs($s['provider']->user);

        Livewire::test(Dashboard::class)->assertSee('Needs attention');

        $this->assertSame('assigned', $s['booking']->fresh()->status, 'Detection is read-only.');
    }

    public function test_job_screen_shows_the_age_nudge(): void
    {
        $s = $this->staleAssignedJob();
        $this->actingAs($s['provider']->user);

        Livewire::test(Show::class, ['booking' => $s['booking']])
            ->assertSee('contact your dispatcher');
    }

    public function test_a_fresh_active_job_is_still_always_listed_on_the_jobs_screen(): void
    {
        $s = $this->makeAssignedBookingScenario(); // just accepted, not stale
        $this->actingAs($s['provider']->user);

        Livewire::test(Index::class)
            ->assertSee("You're on a job", false)
            ->assertDontSee('Needs attention');
    }

    public function test_earnings_shows_wallet_balance_and_per_job_commission(): void
    {
        $s = $this->makeAssignedBookingScenario();
        $s['booking']->update(['status' => 'completed', 'price_final' => 500]);
        Commission::create([
            'booking_id' => $s['booking']->id,
            'provider_commission' => 400, 'franchise_commission' => 75, 'platform_commission' => 25,
        ]);
        app(WalletService::class)->credit($s['provider']->user, 400, 'Earnings for booking '.$s['booking']->code);

        $this->actingAs($s['provider']->user);

        Livewire::test(Earnings::class)
            ->assertSee('400.00')
            ->assertSee($s['booking']->code);
    }

    public function test_history_is_scoped_to_the_provider(): void
    {
        $mine = $this->makeAssignedBookingScenario();
        $theirs = $this->makeAssignedBookingScenario();

        $this->actingAs($mine['provider']->user);

        Livewire::test(History::class)
            ->assertSee($mine['booking']->code)
            ->assertDontSee($theirs['booking']->code);
    }

    public function test_activity_feed_merges_job_and_wallet_events_for_this_provider_only(): void
    {
        $mine = $this->makeAssignedBookingScenario();
        app(WalletService::class)->credit($mine['provider']->user, 250, 'Earnings for booking '.$mine['booking']->code);

        $theirs = $this->makeAssignedBookingScenario();

        $this->actingAs($mine['provider']->user);

        Livewire::test(Activity::class)
            ->assertSee($mine['booking']->code)
            ->assertSee('Earnings for booking '.$mine['booking']->code)
            ->assertDontSee($theirs['booking']->code);
    }
}
