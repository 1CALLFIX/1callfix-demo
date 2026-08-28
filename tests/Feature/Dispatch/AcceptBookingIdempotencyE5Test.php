<?php

namespace Tests\Feature\Dispatch;

use App\Actions\AcceptBookingAction;
use App\Models\DispatchAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Phase E5 — acceptance hardening: a repeat acceptance by the SAME provider,
 * and a lost race by a SECOND provider who also holds a live offer, must
 * each leave exactly one assignment and exactly one accepted dispatch
 * attempt, with no duplicated side effect. The transaction + row lock
 * AcceptBookingAction already uses (E4) is what guarantees this; E5 only
 * pins the serialized outcome down with a test, the same honest way
 * AcceptBookingAvailabilityConcurrencyTest / ServiceMatchingJobRaceTest do
 * under single-threaded PHPUnit.
 */
class AcceptBookingIdempotencyE5Test extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;

    public function test_the_same_provider_re_accepting_produces_no_second_assignment_or_side_effect(): void
    {
        ['booking' => $booking, 'provider' => $provider] = $this->makeBookingScenario('searching_provider');
        DispatchAttempt::create([
            'booking_id' => $booking->id, 'provider_id' => $provider->id,
            'status' => 'notified', 'distance_km' => 1.0, 'notified_at' => now(),
        ]);

        $first = app(AcceptBookingAction::class)->execute($booking->id, $provider);
        $startOtp = $first->start_otp;
        $completionOtp = $first->completion_otp;

        try {
            app(AcceptBookingAction::class)->execute($booking->id, $provider);
            $this->fail('Expected a repeat acceptance to be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already been assigned', $e->getMessage());
        }

        $booking->refresh();
        $this->assertSame($provider->id, $booking->provider_id);
        // The codes are not re-minted, so the customer's delivered OTPs stay valid.
        $this->assertSame($startOtp, $booking->start_otp);
        $this->assertSame($completionOtp, $booking->completion_otp);
        $this->assertSame(1, DispatchAttempt::where('booking_id', $booking->id)->where('status', 'accepted')->count());
    }

    public function test_a_second_provider_with_a_live_offer_loses_the_race_cleanly(): void
    {
        ['booking' => $booking, 'provider' => $providerA, 'franchise' => $franchise, 'zone' => $zone] = $this->makeBookingScenario('searching_provider');
        $providerB = $this->makeProviderIn($franchise, $zone);

        DispatchAttempt::create(['booking_id' => $booking->id, 'provider_id' => $providerA->id, 'status' => 'notified', 'distance_km' => 1.0, 'notified_at' => now()]);
        DispatchAttempt::create(['booking_id' => $booking->id, 'provider_id' => $providerB->id, 'status' => 'notified', 'distance_km' => 2.0, 'notified_at' => now()]);

        app(AcceptBookingAction::class)->execute($booking->id, $providerA);

        try {
            app(AcceptBookingAction::class)->execute($booking->id, $providerB);
            $this->fail('Expected the second provider to be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already been assigned', $e->getMessage());
        }

        $this->assertSame($providerA->id, $booking->fresh()->provider_id);
        $this->assertSame(1, DispatchAttempt::where('booking_id', $booking->id)->where('status', 'accepted')->count());
        $this->assertSame(0, DispatchAttempt::where('booking_id', $booking->id)->where('provider_id', $providerB->id)->where('status', 'accepted')->count());
    }
}
