<?php

namespace Tests\Feature\Booking;

use App\Actions\AcceptBookingAction;
use App\Actions\CompleteBookingAction;
use App\Actions\StartBookingAction;
use App\Models\Booking;
use App\Models\DispatchAttempt;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Phase E5 — the hardening the existing Service booking OTP grew in this
 * phase: expiry, an attempt cap, and single-use / replay protection, on top
 * of the unchanged plain-string compare and the unchanged "wrong code ->
 * RuntimeException, retry allowed, booking NOT cancelled" contract.
 *
 * OTP_ARCHITECTURE.md recorded those three as deliberately-deferred gaps
 * ("carried forward as a known gap, not invented as a fix without
 * approval"). E5 is that approval. BookingFsmTest still owns the base
 * transition assertions; this file only covers what E5 added, plus the
 * per-booking / per-actor isolation of the codes.
 */
class BookingOtpHardeningTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;

    // ============================ generation stamps the metadata ============================

    public function test_accepting_a_booking_stamps_expiry_and_zeroes_the_attempt_counters(): void
    {
        ['booking' => $booking, 'provider' => $provider] = $this->makeBookingScenario('searching_provider');
        DispatchAttempt::create([
            'booking_id' => $booking->id, 'provider_id' => $provider->id,
            'status' => 'notified', 'distance_km' => 1.0, 'notified_at' => now(),
        ]);

        $accepted = app(AcceptBookingAction::class)->execute($booking->id, $provider)->fresh();

        $this->assertNotNull($accepted->start_otp_expires_at);
        $this->assertNotNull($accepted->completion_otp_expires_at);
        $this->assertTrue($accepted->start_otp_expires_at->isFuture());
        $this->assertSame(0, (int) $accepted->start_otp_attempts);
        $this->assertSame(0, (int) $accepted->completion_otp_attempts);
        $this->assertNull($accepted->start_otp_verified_at);
        $this->assertNull($accepted->completion_otp_verified_at);
    }

    public function test_expiry_runs_from_the_scheduled_start_not_from_acceptance(): void
    {
        ['booking' => $booking, 'provider' => $provider] = $this->makeBookingScenario('searching_provider');
        $booking->update(['scheduled_at' => now()->addDays(7)]);
        DispatchAttempt::create([
            'booking_id' => $booking->id, 'provider_id' => $provider->id,
            'status' => 'notified', 'distance_km' => 1.0, 'notified_at' => now(),
        ]);
        Setting::set('booking.otp_ttl_minutes', '1440');

        $accepted = app(AcceptBookingAction::class)->execute($booking->id, $provider)->fresh();

        // A booking scheduled a week out must not get a code that expired
        // 24h after it was merely accepted.
        $this->assertTrue($accepted->start_otp_expires_at->greaterThan(now()->addDays(7)));
    }

    // ============================ START OTP ============================

    public function test_valid_start_otp_transitions_and_consumes_the_code(): void
    {
        ['booking' => $booking] = $this->makeAssignedBookingScenario();

        $result = app(StartBookingAction::class)->execute($booking->id, '1234');

        $this->assertSame('in_progress', $result->status);
        $this->assertNull($result->start_otp, 'A consumed start OTP must be cleared.');
        $this->assertNotNull($result->start_otp_verified_at);
    }

    public function test_wrong_start_otp_is_rejected_and_counts_one_attempt_but_leaves_the_code_usable(): void
    {
        ['booking' => $booking] = $this->makeAssignedBookingScenario();

        try {
            app(StartBookingAction::class)->execute($booking->id, '0000');
            $this->fail('Expected a wrong start OTP to be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Incorrect start OTP', $e->getMessage());
        }

        $booking->refresh();
        $this->assertSame(1, (int) $booking->start_otp_attempts);
        $this->assertSame('assigned', $booking->status);

        // Retry with the correct code still works — a wrong attempt does not
        // burn the code, matching the mission's "allow retry" instruction.
        $result = app(StartBookingAction::class)->execute($booking->id, '1234');
        $this->assertSame('in_progress', $result->status);
    }

    public function test_expired_start_otp_is_rejected(): void
    {
        ['booking' => $booking] = $this->makeAssignedBookingScenario();
        $booking->update(['start_otp_expires_at' => now()->subMinute()]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('start OTP has expired');
        app(StartBookingAction::class)->execute($booking->id, '1234');
    }

    public function test_a_consumed_start_otp_cannot_be_replayed_even_if_the_status_is_forced_back(): void
    {
        ['booking' => $booking] = $this->makeAssignedBookingScenario();
        app(StartBookingAction::class)->execute($booking->id, '1234');

        // Simulate some other path putting the booking back into a startable
        // state (query-builder update so it actually writes) — the OTP
        // itself must still refuse a replay.
        Booking::whereKey($booking->id)->update(['status' => 'assigned']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('start OTP has already been used');
        app(StartBookingAction::class)->execute($booking->id, '1234');
    }

    public function test_start_otp_attempts_are_capped(): void
    {
        ['booking' => $booking] = $this->makeAssignedBookingScenario();
        Setting::set('booking.otp_max_attempts', '5');

        for ($i = 0; $i < 5; $i++) {
            try {
                app(StartBookingAction::class)->execute($booking->id, '9999');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Incorrect start OTP', $e->getMessage());
            }
        }

        // 6th attempt — even the CORRECT code is now refused until reissue.
        try {
            app(StartBookingAction::class)->execute($booking->id, '1234');
            $this->fail('Expected the attempt cap to refuse further tries.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Too many incorrect start OTP attempts', $e->getMessage());
        }

        $this->assertSame('assigned', $booking->fresh()->status);
    }

    public function test_a_start_otp_issued_for_one_booking_cannot_start_another(): void
    {
        ['booking' => $bookingA] = $this->makeAssignedBookingScenario();
        $bookingA->update(['start_otp' => '1111']);
        ['booking' => $bookingB] = $this->makeAssignedBookingScenario();
        $bookingB->update(['start_otp' => '2222']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Incorrect start OTP');
        app(StartBookingAction::class)->execute($bookingB->id, '1111');
    }

    // ============================ COMPLETION OTP ============================

    public function test_valid_completion_otp_completes_and_consumes_the_code(): void
    {
        ['booking' => $booking, 'provider' => $provider] = $this->makeAssignedBookingScenario();

        $result = app(CompleteBookingAction::class)->execute($booking->id, $provider, '5678');

        $this->assertSame('completed', $result->status);
        $this->assertNull($result->completion_otp);
        $this->assertNotNull($result->completion_otp_verified_at);
    }

    public function test_wrong_completion_otp_is_rejected_and_does_not_advance_the_booking(): void
    {
        ['booking' => $booking, 'provider' => $provider] = $this->makeAssignedBookingScenario();

        try {
            app(CompleteBookingAction::class)->execute($booking->id, $provider, '0000');
            $this->fail('Expected a wrong completion OTP to be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Incorrect completion OTP', $e->getMessage());
        }

        $booking->refresh();
        $this->assertSame('assigned', $booking->status);
        $this->assertSame(1, (int) $booking->completion_otp_attempts);
        $this->assertSame(0, \App\Models\Commission::where('booking_id', $booking->id)->count());
    }

    public function test_expired_completion_otp_is_rejected(): void
    {
        ['booking' => $booking, 'provider' => $provider] = $this->makeAssignedBookingScenario();
        $booking->update(['completion_otp_expires_at' => now()->subMinute()]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('completion OTP has expired');
        app(CompleteBookingAction::class)->execute($booking->id, $provider, '5678');
    }

    public function test_a_completion_otp_cannot_be_replayed(): void
    {
        ['booking' => $booking, 'provider' => $provider] = $this->makeAssignedBookingScenario();
        app(CompleteBookingAction::class)->execute($booking->id, $provider, '5678');

        // Force the booking back to a completable status (query-builder
        // update so it actually writes); the single-use guard on the OTP
        // itself must still refuse the replayed code.
        Booking::whereKey($booking->id)->update(['status' => 'in_progress']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('completion OTP has already been used');
        app(CompleteBookingAction::class)->execute($booking->id, $provider, '5678');
    }

    public function test_completion_otp_attempts_are_capped(): void
    {
        ['booking' => $booking, 'provider' => $provider] = $this->makeAssignedBookingScenario();
        Setting::set('booking.otp_max_attempts', '5');

        for ($i = 0; $i < 5; $i++) {
            try {
                app(CompleteBookingAction::class)->execute($booking->id, $provider, '9999');
            } catch (\RuntimeException $e) {
                // expected
            }
        }

        try {
            app(CompleteBookingAction::class)->execute($booking->id, $provider, '5678');
            $this->fail('Expected the attempt cap to refuse further tries.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Too many incorrect completion OTP attempts', $e->getMessage());
        }

        $this->assertSame('assigned', $booking->fresh()->status);
        $this->assertSame(0, \App\Models\Commission::where('booking_id', $booking->id)->count());
    }

    public function test_a_completion_otp_issued_for_one_booking_cannot_complete_another(): void
    {
        ['booking' => $bookingA] = $this->makeAssignedBookingScenario();
        $bookingA->update(['completion_otp' => '1111']);
        ['booking' => $bookingB, 'provider' => $providerB] = $this->makeAssignedBookingScenario();
        $bookingB->update(['completion_otp' => '2222']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Incorrect completion OTP');
        app(CompleteBookingAction::class)->execute($bookingB->id, $providerB, '1111');
    }

    public function test_wrong_provider_is_rejected_before_the_completion_otp_is_ever_examined(): void
    {
        ['booking' => $booking, 'franchise' => $franchise, 'zone' => $zone] = $this->makeAssignedBookingScenario();
        $intruder = $this->makeProviderIn($franchise, $zone);

        try {
            app(CompleteBookingAction::class)->execute($booking->id, $intruder, '5678');
            $this->fail('Expected an unassigned provider to be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not assigned to you', $e->getMessage());
        }

        // The ownership gate fired first: the OTP counter was never touched.
        $this->assertSame(0, (int) $booking->fresh()->completion_otp_attempts);
    }
}
