<?php

namespace Tests\Feature\Dispatch;

use App\Actions\AdminReassignBookingAction;
use App\Livewire\LiveDispatchCalls;
use App\Livewire\Settings\Manage as SettingsManage;
use App\Models\Setting;
use App\Models\User;
use App\Services\DispatchDeadlineSweepService;
use App\Support\DispatchTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Owner request 2026-10-20. Live on 2026-10-19 the broadcast was 12 rounds × 50 s = 10 min and the
 * searching-provider auto-cancel was also 10 min, so booking NLR-1910-00000135 was cancelled the moment the
 * broadcast ended, and an admin's assign 2 s later attached a provider to the cancelled booking.
 */
class DispatchAdminWindowTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    private function liveSettings(): void
    {
        Setting::set('dispatch.max_rounds', '12');
        Setting::set('dispatch.offer_timeout_seconds', '50');
        Setting::set('operations.stuck_threshold_minutes.searching_provider', '10');
    }

    private function superAdmin(): User
    {
        return User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Admin', 'phone' => '8'.fake()->unique()->numerify('#########'),
            'role' => 'super_admin', 'status' => 'active',
        ]);
    }

    public function test_live_settings_no_longer_cancel_when_the_broadcast_ends(): void
    {
        $this->liveSettings();
        ['booking' => $booking] = $this->makeBookingScenario('searching_provider');
        $booking->update(['dispatch_deadline_at' => now()->subMinutes(11)]);

        app(DispatchDeadlineSweepService::class)->sweep();

        $this->assertSame('searching_provider', $booking->fresh()->status, 'admins keep the minimum window (10 min) after the 10-min broadcast');
        $this->assertSame(20, DispatchTimeline::effectiveCancelMinutes());
    }

    public function test_it_cancels_once_the_admin_window_has_passed(): void
    {
        $this->liveSettings();
        ['booking' => $booking] = $this->makeBookingScenario('searching_provider');
        $booking->update(['dispatch_deadline_at' => now()->subMinutes(21)]);

        app(DispatchDeadlineSweepService::class)->sweep();

        $this->assertSame('cancelled', $booking->fresh()->status);
    }

    public function test_a_longer_configured_cancel_time_is_kept(): void
    {
        $this->liveSettings();
        Setting::set('operations.stuck_threshold_minutes.searching_provider', '30');
        ['booking' => $booking] = $this->makeBookingScenario('searching_provider');
        $booking->update(['dispatch_deadline_at' => now()->subMinutes(25)]);

        app(DispatchDeadlineSweepService::class)->sweep();

        $this->assertSame('searching_provider', $booking->fresh()->status);
    }

    public function test_settings_refuse_an_auto_cancel_inside_the_broadcast_plus_window(): void
    {
        $this->liveSettings();

        Livewire::actingAs($this->superAdmin())->test(SettingsManage::class)
            ->set('activeTab', 'operations')
            ->assertSee('Instant booking call timeline')
            ->set('opsStuckThresholdSearchingProvider', '10')
            ->call('saveOperations')
            ->assertHasErrors('opsStuckThresholdSearchingProvider')
            ->set('opsStuckThresholdSearchingProvider', '30')
            ->call('saveOperations')
            ->assertHasNoErrors();

        $this->assertSame('30', (string) Setting::get('operations.stuck_threshold_minutes.searching_provider'));
    }

    public function test_dispatch_settings_refuse_a_broadcast_that_eats_the_admin_window(): void
    {
        Setting::set('operations.stuck_threshold_minutes.searching_provider', '30');

        Livewire::actingAs($this->superAdmin())->test(SettingsManage::class)
            ->set('activeTab', 'dispatch')
            ->set('dispatchMaxRounds', '40')
            ->set('dispatchOfferTimeoutSeconds', '50')
            ->call('saveDispatch')
            ->assertHasErrors('dispatchMaxRounds');

        $this->assertNotSame('40', (string) Setting::get('dispatch.max_rounds'));
    }

    public function test_admin_cannot_assign_a_provider_to_a_cancelled_booking(): void
    {
        ['booking' => $booking, 'franchise' => $franchise, 'zone' => $zone] = $this->makeBookingScenario('searching_provider');
        $provider = $this->makeProviderIn($franchise, $zone);
        $provider->update(['skills' => [$booking->service->category_id]]);
        $booking->forceFill(['status' => 'cancelled'])->saveQuietly();
        $historyBefore = $booking->statusHistory()->count();

        try {
            app(AdminReassignBookingAction::class)->execute($booking->id, $provider->id);
            $this->fail('assigning a cancelled booking must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already cancelled', $e->getMessage());
        }

        $this->assertNull($booking->fresh()->provider_id);
        $this->assertSame($historyBefore, $booking->statusHistory()->count());
    }

    public function test_live_calls_bar_lists_searching_instant_bookings_with_their_countdown(): void
    {
        $this->liveSettings();
        ['booking' => $searching] = $this->makeBookingScenario('searching_provider');
        $searching->update(['dispatch_deadline_at' => now()->subMinutes(3)]);
        ['booking' => $scheduled] = $this->makeBookingScenario('searching_provider');
        $scheduled->update(['dispatch_deadline_at' => now(), 'scheduled_at' => now()->addDay()]);

        $cancelsAt = $searching->fresh()->dispatch_deadline_at->copy()->addMinutes(20)->getTimestampMs();

        Livewire::actingAs($this->superAdmin())->test(LiveDispatchCalls::class)
            ->assertSee('Live calls')
            ->assertSee($searching->code)
            ->assertDontSee($scheduled->code)
            ->assertSeeHtml('data-cancels-at="'.$cancelsAt.'"');
    }

    public function test_live_calls_bar_is_empty_for_a_customer(): void
    {
        ['booking' => $booking, 'customer' => $customer] = $this->makeBookingScenario('searching_provider');
        $booking->update(['dispatch_deadline_at' => now()]);

        Livewire::actingAs($customer)->test(LiveDispatchCalls::class)
            ->assertDontSee($booking->code)
            ->assertDontSee('Live calls');
    }
}
