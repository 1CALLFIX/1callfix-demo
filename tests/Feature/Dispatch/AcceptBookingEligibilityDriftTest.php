<?php

namespace Tests\Feature\Dispatch;

use App\Actions\AcceptBookingAction;
use App\Models\Setting;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BundleConsolidationHelpers;
use Tests\TestCase;

/**
 * REF 1CF-LAUNCH-011 — closes the LAUNCH-009 audit's "AcceptBookingAction
 * eligibility drift" finding: a provider could receive a valid offer while
 * genuinely eligible, then go offline/stale/inactive/unskilled/rezoned/lose
 * KYC before tapping Accept, and the booking would still be assigned to
 * them regardless — nothing between offer and accept ever re-checked any
 * of DispatchService::providerEligibleForBooking()'s rules. Proves the fix
 * reuses that SAME authoritative check (including LAUNCH-008's
 * location-freshness rule) rather than duplicating any of its rules here —
 * every "rejected" case below is a state providerEligibleForBooking()
 * already independently covers, not a new rule invented for this test.
 */
class AcceptBookingEligibilityDriftTest extends TestCase
{
    use RefreshDatabase;
    use BundleConsolidationHelpers;

    /** A genuinely eligible provider + a live offer on a real, unscheduled booking, ready to accept. */
    private function eligibleOfferScenario(): array
    {
        $ctx = $this->makeWorld();
        $service = $this->makeService($ctx['category'], 60);
        $provider = $this->makeSkilledProvider($ctx['franchise'], $ctx['zone'], $ctx['category']->id);
        $booking = $this->makeScheduledBooking($ctx, $service, null); // immediate — no schedule-conflict noise
        $this->offer($booking, $provider);

        return compact('ctx', 'service', 'provider', 'booking');
    }

    private function assertAcceptanceRejectedForIneligibility(int $bookingId, $provider): void
    {
        try {
            app(AcceptBookingAction::class)->execute($bookingId, $provider);
            $this->fail('Expected the acceptance to be rejected for lost eligibility.');
        } catch (\RuntimeException $e) {
            $this->assertSame('You are no longer eligible for this job offer.', $e->getMessage());
        }
    }

    // ============================== 1-7: each eligibility rule LAUNCH-011 now re-checks ==============================

    public function test_provider_who_goes_offline_after_the_offer_cannot_accept(): void
    {
        ['provider' => $provider, 'booking' => $booking] = $this->eligibleOfferScenario();
        $provider->update(['is_online' => false]);

        $this->assertAcceptanceRejectedForIneligibility($booking->id, $provider);
    }

    public function test_provider_whose_location_goes_stale_after_the_offer_cannot_accept(): void
    {
        ['provider' => $provider, 'booking' => $booking] = $this->eligibleOfferScenario();
        $threshold = max(1, (int) Setting::get('provider.location_stale_after_minutes', '30'));
        $provider->update(['location_updated_at' => now()->subMinutes($threshold + 5)]);

        $this->assertAcceptanceRejectedForIneligibility($booking->id, $provider);
    }

    public function test_provider_who_becomes_inactive_after_the_offer_cannot_accept(): void
    {
        ['provider' => $provider, 'booking' => $booking] = $this->eligibleOfferScenario();
        $provider->update(['is_active' => false]);

        $this->assertAcceptanceRejectedForIneligibility($booking->id, $provider);
    }

    public function test_provider_who_loses_kyc_approval_after_the_offer_cannot_accept(): void
    {
        ['provider' => $provider, 'booking' => $booking] = $this->eligibleOfferScenario();
        $provider->update(['kyc_status' => 'pending']);

        $this->assertAcceptanceRejectedForIneligibility($booking->id, $provider);
    }

    public function test_provider_reassigned_to_a_different_zone_after_the_offer_cannot_accept(): void
    {
        ['ctx' => $ctx, 'provider' => $provider, 'booking' => $booking] = $this->eligibleOfferScenario();

        $otherZone = Zone::create([
            'franchise_id' => $ctx['franchise']->id, 'name' => 'Other Zone',
            'boundary_polygon' => [['lat' => 5, 'lng' => 5], ['lat' => 6, 'lng' => 6], ['lat' => 7, 'lng' => 7]],
            'is_active' => true, 'default_dispatch_radius_km' => 8,
        ]);
        $provider->update(['zone_id' => $otherZone->id]);

        $this->assertAcceptanceRejectedForIneligibility($booking->id, $provider);
    }

    public function test_provider_who_loses_the_required_skill_after_the_offer_cannot_accept(): void
    {
        ['provider' => $provider, 'booking' => $booking] = $this->eligibleOfferScenario();
        $provider->update(['skills' => []]);

        $this->assertAcceptanceRejectedForIneligibility($booking->id, $provider);
    }

    public function test_provider_who_loses_coordinates_after_the_offer_cannot_accept(): void
    {
        ['provider' => $provider, 'booking' => $booking] = $this->eligibleOfferScenario();
        $provider->update(['current_lat' => null, 'current_lng' => null]);

        $this->assertAcceptanceRejectedForIneligibility($booking->id, $provider);
    }

    // ============================== 8: the happy path is unaffected ==============================

    public function test_a_provider_who_remains_eligible_can_still_accept(): void
    {
        ['provider' => $provider, 'booking' => $booking] = $this->eligibleOfferScenario();

        $accepted = app(AcceptBookingAction::class)->execute($booking->id, $provider);

        $this->assertSame('assigned', $accepted->status);
        $this->assertSame($provider->id, $accepted->provider_id);
        $this->assertNotNull($accepted->start_otp);
        $this->assertDatabaseHas('dispatch_attempts', [
            'booking_id' => $booking->id, 'provider_id' => $provider->id, 'status' => 'accepted',
        ]);
    }

    // ============================== 9: existing concurrent-acceptance protection ==============================

    public function test_concurrent_acceptance_by_two_different_providers_is_still_correctly_serialized(): void
    {
        ['ctx' => $ctx, 'provider' => $providerA, 'booking' => $booking] = $this->eligibleOfferScenario();
        $providerB = $this->makeSkilledProvider($ctx['franchise'], $ctx['zone'], $ctx['category']->id, lng: 1.002);
        $this->offer($booking, $providerB);

        $accepted = app(AcceptBookingAction::class)->execute($booking->id, $providerA);
        $this->assertSame($providerA->id, $accepted->provider_id);

        try {
            app(AcceptBookingAction::class)->execute($booking->id, $providerB);
            $this->fail("Expected provider B's acceptance to be rejected.");
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already been assigned', $e->getMessage());
        }

        $this->assertSame($providerA->id, $booking->fresh()->provider_id);
    }

    // ============================== 10: existing provider-scoped DispatchAttempt protection ==============================

    public function test_provider_without_a_live_offer_for_this_booking_still_cannot_accept(): void
    {
        ['ctx' => $ctx, 'booking' => $booking] = $this->eligibleOfferScenario();
        // Genuinely eligible in every respect -- just never actually offered this booking.
        $neverOfferedProvider = $this->makeSkilledProvider($ctx['franchise'], $ctx['zone'], $ctx['category']->id, lng: 1.003);

        try {
            app(AcceptBookingAction::class)->execute($booking->id, $neverOfferedProvider);
            $this->fail('Expected acceptance without a live offer to be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('no longer available', $e->getMessage());
        }

        $this->assertNull($booking->fresh()->provider_id);
    }

    // ============================== 11-12: state proof after a rejected acceptance ==============================

    public function test_booking_remains_unassigned_after_an_eligibility_rejected_acceptance(): void
    {
        ['provider' => $provider, 'booking' => $booking] = $this->eligibleOfferScenario();
        $provider->update(['is_online' => false]);

        $this->assertAcceptanceRejectedForIneligibility($booking->id, $provider);

        $booking->refresh();
        $this->assertNull($booking->provider_id);
        $this->assertSame('searching_provider', $booking->status);
        $this->assertNull($booking->start_otp, 'No OTP should ever be generated for a rejected acceptance.');
        $this->assertNull($booking->completion_otp);
    }

    public function test_dispatch_attempt_does_not_become_accepted_after_an_eligibility_rejected_acceptance(): void
    {
        ['provider' => $provider, 'booking' => $booking] = $this->eligibleOfferScenario();
        $provider->update(['is_active' => false]);

        $this->assertAcceptanceRejectedForIneligibility($booking->id, $provider);

        $this->assertDatabaseHas('dispatch_attempts', [
            'booking_id' => $booking->id, 'provider_id' => $provider->id, 'status' => 'notified',
        ]);
        $this->assertDatabaseMissing('dispatch_attempts', [
            'booking_id' => $booking->id, 'provider_id' => $provider->id, 'status' => 'accepted',
        ]);
    }
}
