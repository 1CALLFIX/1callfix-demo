<?php

namespace Tests\Feature\Compensation;

use App\Livewire\Bookings\Show as BookingsShow;
use App\Models\Booking;
use App\Models\BookingCompensation;
use App\Models\Setting;
use App\Services\CompensationService;
use App\Services\TipService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Tips + waiting/rain/overtime/peak/night compensation (mission Phase 5).
 * Every rate defaults to 0 (no effect) — tests explicitly set a Setting
 * before asserting a payout, never relying on a hidden default value. All
 * money movement goes through WalletService — verified via wallet balance,
 * never a direct DB column check on a balance field.
 */
class CompensationEngineTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    private function setSetting(string $key, string $value): void
    {
        Setting::create(['scope_type' => 'global', 'scope_id' => null, 'key' => $key, 'value' => $value]);
    }

    private function completedBooking(array $overrides = []): Booking
    {
        $scenario = $this->makeAssignedBookingScenario();
        $booking = $scenario['booking'];
        $booking->update(array_merge([
            'status' => 'completed',
            'price_final' => 500,
            'scheduled_at' => now()->subHours(2),
            'completed_at' => now(),
        ], $overrides));

        return $booking->fresh(['provider.user', 'service']);
    }

    // ============================== Overtime ==============================

    public function test_overtime_disabled_by_default(): void
    {
        $booking = $this->completedBooking(['scheduled_at' => now()->subHours(5), 'completed_at' => now()]);

        app(CompensationService::class)->applyAutomaticForBooking($booking);

        $this->assertSame(0.0, app(WalletService::class)->balance($booking->provider->user));
    }

    public function test_overtime_pays_when_actual_duration_exceeds_estimate_plus_threshold(): void
    {
        $this->setSetting('compensation.overtime_rate_per_minute', '5');
        $this->setSetting('compensation.overtime_threshold_minutes', '10');

        // Service duration_estimate_mins = 60 (BookingFixtureHelpers default). Scheduled->completed = 90 minutes -> 90-60-10 = 20 overtime minutes.
        $booking = $this->completedBooking(['scheduled_at' => now()->subMinutes(90), 'completed_at' => now()]);

        app(CompensationService::class)->applyAutomaticForBooking($booking);

        $this->assertSame(100.0, app(WalletService::class)->balance($booking->provider->user)); // 20 * 5
        $this->assertSame(1, BookingCompensation::where('booking_id', $booking->id)->where('type', 'overtime')->count());
    }

    public function test_overtime_does_not_pay_within_threshold(): void
    {
        $this->setSetting('compensation.overtime_rate_per_minute', '5');
        $this->setSetting('compensation.overtime_threshold_minutes', '30');

        $booking = $this->completedBooking(['scheduled_at' => now()->subMinutes(70), 'completed_at' => now()]); // 70-60-30 = -20

        app(CompensationService::class)->applyAutomaticForBooking($booking);

        $this->assertSame(0.0, app(WalletService::class)->balance($booking->provider->user));
    }

    public function test_overtime_idempotent_never_double_pays(): void
    {
        $this->setSetting('compensation.overtime_rate_per_minute', '5');
        $booking = $this->completedBooking(['scheduled_at' => now()->subMinutes(90), 'completed_at' => now()]);

        $service = app(CompensationService::class);
        $service->applyAutomaticForBooking($booking);
        $service->applyAutomaticForBooking($booking);

        $this->assertSame(1, BookingCompensation::where('booking_id', $booking->id)->where('type', 'overtime')->count());
    }

    // ============================== Night / peak windows ==============================

    public function test_night_compensation_pays_within_configured_window(): void
    {
        $this->setSetting('compensation.night_flat_amount', '30');
        $this->setSetting('compensation.night_window_start_hour', '22');
        $this->setSetting('compensation.night_window_end_hour', '6');

        $booking = $this->completedBooking(['completed_at' => now()->setTime(23, 0)]);

        app(CompensationService::class)->applyAutomaticForBooking($booking);

        $this->assertSame(30.0, app(WalletService::class)->balance($booking->provider->user));
    }

    public function test_night_compensation_handles_midnight_wraparound(): void
    {
        $this->setSetting('compensation.night_flat_amount', '30');
        $this->setSetting('compensation.night_window_start_hour', '22');
        $this->setSetting('compensation.night_window_end_hour', '6');

        $booking = $this->completedBooking(['completed_at' => now()->setTime(3, 0)]);

        app(CompensationService::class)->applyAutomaticForBooking($booking);

        $this->assertSame(30.0, app(WalletService::class)->balance($booking->provider->user));
    }

    public function test_night_compensation_does_not_pay_outside_window(): void
    {
        $this->setSetting('compensation.night_flat_amount', '30');
        $this->setSetting('compensation.night_window_start_hour', '22');
        $this->setSetting('compensation.night_window_end_hour', '6');

        $booking = $this->completedBooking(['completed_at' => now()->setTime(14, 0)]);

        app(CompensationService::class)->applyAutomaticForBooking($booking);

        $this->assertSame(0.0, app(WalletService::class)->balance($booking->provider->user));
    }

    public function test_peak_and_night_are_independent_and_can_both_apply(): void
    {
        $this->setSetting('compensation.night_flat_amount', '30');
        $this->setSetting('compensation.night_window_start_hour', '22');
        $this->setSetting('compensation.night_window_end_hour', '6');
        $this->setSetting('compensation.peak_flat_amount', '15');
        $this->setSetting('compensation.peak_window_start_hour', '22');
        $this->setSetting('compensation.peak_window_end_hour', '23');

        $booking = $this->completedBooking(['completed_at' => now()->setTime(22, 30)]);

        app(CompensationService::class)->applyAutomaticForBooking($booking);

        $this->assertSame(45.0, app(WalletService::class)->balance($booking->provider->user));
        $this->assertSame(2, BookingCompensation::where('booking_id', $booking->id)->count());
    }

    public function test_window_not_configured_never_guesses_a_default(): void
    {
        $this->setSetting('compensation.night_flat_amount', '30');
        // window hours left unconfigured (-1 sentinel)

        $booking = $this->completedBooking(['completed_at' => now()->setTime(23, 0)]);

        app(CompensationService::class)->applyAutomaticForBooking($booking);

        $this->assertSame(0.0, app(WalletService::class)->balance($booking->provider->user));
    }

    // ============================== Manual (rain/waiting) ==============================

    public function test_manual_rain_requires_configured_rate(): void
    {
        $booking = $this->completedBooking();
        $admin = $this->makeSuperAdmin();

        $this->expectException(\RuntimeException::class);
        app(CompensationService::class)->applyManual($booking, 'rain', $admin);
    }

    public function test_manual_rain_pays_flat_configured_amount(): void
    {
        $this->setSetting('compensation.rain_flat_amount', '75');
        $booking = $this->completedBooking();
        $admin = $this->makeSuperAdmin();

        app(CompensationService::class)->applyManual($booking, 'rain', $admin);

        $this->assertSame(75.0, app(WalletService::class)->balance($booking->provider->user));
    }

    public function test_manual_waiting_pays_rate_times_minutes(): void
    {
        $this->setSetting('compensation.waiting_rate_per_minute', '2');
        $booking = $this->completedBooking();
        $admin = $this->makeSuperAdmin();

        app(CompensationService::class)->applyManual($booking, 'waiting', $admin, 15);

        $this->assertSame(30.0, app(WalletService::class)->balance($booking->provider->user));
    }

    public function test_manual_cannot_apply_auto_computed_types(): void
    {
        $booking = $this->completedBooking();
        $admin = $this->makeSuperAdmin();

        $this->expectException(\InvalidArgumentException::class);
        app(CompensationService::class)->applyManual($booking, 'overtime', $admin);
    }

    public function test_manual_rejects_duplicate_type_for_same_booking(): void
    {
        $this->setSetting('compensation.rain_flat_amount', '75');
        $booking = $this->completedBooking();
        $admin = $this->makeSuperAdmin();

        app(CompensationService::class)->applyManual($booking, 'rain', $admin);

        $this->expectException(\RuntimeException::class);
        app(CompensationService::class)->applyManual($booking->fresh(), 'rain', $admin);
    }

    public function test_livewire_apply_compensation_requires_permission(): void
    {
        $this->setSetting('compensation.rain_flat_amount', '75');
        $booking = $this->completedBooking();
        $actor = $this->makeUserWithPermission('bookings.view', 'global');

        Livewire::actingAs($actor)->test(BookingsShow::class, ['bookingId' => $booking->id])
            ->set('compensationType', 'rain')->call('applyCompensation');

        $this->assertSame(0, BookingCompensation::count());
    }

    public function test_livewire_apply_compensation_succeeds_with_permission(): void
    {
        $this->setSetting('compensation.rain_flat_amount', '75');
        $booking = $this->completedBooking();
        $actor = $this->makeUserWithPermission('bookings.view', 'global');
        $this->grantPermission($actor, 'bookings.compensate', 'global');

        Livewire::actingAs($actor)->test(BookingsShow::class, ['bookingId' => $booking->id])
            ->set('compensationType', 'rain')->call('applyCompensation');

        $this->assertSame(1, BookingCompensation::count());
    }

    // ============================== Tips ==============================

    public function test_tip_moves_money_from_customer_to_provider(): void
    {
        $booking = $this->completedBooking();
        app(WalletService::class)->credit($booking->customer, 200, 'test seed');

        app(TipService::class)->addTip($booking, $booking->customer, 50);

        $this->assertSame(150.0, app(WalletService::class)->balance($booking->customer));
        $this->assertSame(50.0, app(WalletService::class)->balance($booking->provider->user));
    }

    public function test_tip_fails_with_insufficient_customer_balance(): void
    {
        $booking = $this->completedBooking();

        $this->expectException(\RuntimeException::class);
        app(TipService::class)->addTip($booking, $booking->customer, 50);
    }

    public function test_tip_rejects_non_completed_booking(): void
    {
        $scenario = $this->makeAssignedBookingScenario();
        app(WalletService::class)->credit($scenario['customer'], 200, 'test seed');

        $this->expectException(\RuntimeException::class);
        app(TipService::class)->addTip($scenario['booking'], $scenario['customer'], 50);
    }

    public function test_tip_rejects_wrong_customer(): void
    {
        $booking = $this->completedBooking();
        $otherCustomer = $this->makeCustomer();
        app(WalletService::class)->credit($otherCustomer, 200, 'test seed');

        $this->expectException(\RuntimeException::class);
        app(TipService::class)->addTip($booking, $otherCustomer, 50);
    }

    public function test_tip_rejects_duplicate_for_same_booking(): void
    {
        $booking = $this->completedBooking();
        app(WalletService::class)->credit($booking->customer, 200, 'test seed');
        app(TipService::class)->addTip($booking, $booking->customer, 50);

        $this->expectException(\RuntimeException::class);
        app(TipService::class)->addTip($booking->fresh(), $booking->customer, 20);
    }

    // ============================== End-to-end integration ==============================

    public function test_complete_booking_action_triggers_automatic_compensation(): void
    {
        $this->setSetting('compensation.night_flat_amount', '30');
        $this->setSetting('compensation.night_window_start_hour', '22');
        $this->setSetting('compensation.night_window_end_hour', '6');

        $this->travelTo(now()->setTime(23, 0));
        $scenario = $this->makeAssignedBookingScenario();
        $result = app(\App\Actions\CompleteBookingAction::class)->execute($scenario['booking']->id, $scenario['provider'], '5678');

        // Provider's wallet also received real commission from
        // CompleteBookingAction's own CommissionService call — this test
        // only asserts the compensation SLICE of that balance, via its own
        // audit row, not the whole wallet balance (which is commission's
        // concern to test, not this suite's).
        $compensation = BookingCompensation::where('booking_id', $result->id)->where('type', 'night')->first();
        $this->assertNotNull($compensation);
        $this->assertSame(30.0, (float) $compensation->amount);
    }
}
