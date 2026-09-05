<?php

namespace Tests\Feature\Finance;

use App\Models\Booking;
use App\Models\ProviderCommissionAgreement;
use App\Services\CommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\TestCase;

/**
 * Provider Commercial Rate Resolver phase — Step 10 C. CommissionService is
 * the one place platform_fee_percent is actually spent; these prove the new
 * resolver is really wired into both call sites (applyForBooking() for the
 * Service vertical, applyForFieldWorkerOrder() for everyone else) without
 * disturbing the split math itself.
 */
class ProviderCommercialRateIntegrationTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;
    use ParcelOrderFixtureHelpers;

    private function completeBooking(array $scenario): Booking
    {
        $scenario['booking']->update([
            'provider_id' => $scenario['provider']->id,
            'status' => 'completed', 'price_final' => 1000, 'payment_status' => 'paid',
            'completed_at' => now(),
        ]);

        return $scenario['booking']->fresh();
    }

    public function test_service_booking_splits_at_the_existing_franchise_rate_unchanged(): void
    {
        // Regression proof: franchise.platform_fee_percent = 5 (makeFranchiseTree's
        // default), no agreement — must resolve exactly like before this
        // phase existed.
        $scenario = $this->makeBookingScenario();
        $booking = $this->completeBooking($scenario);

        $commission = app(CommissionService::class)->applyForBooking($booking);

        // 5% platform, 10% franchise (commission_value default), remainder provider — on 1000.
        $this->assertEquals(50.0, $commission->platform_commission);
        $this->assertEquals(100.0, $commission->franchise_commission);
        $this->assertEquals(850.0, $commission->provider_commission);
    }

    public function test_service_booking_with_unconfigured_franchise_uses_the_seeded_global_default(): void
    {
        $scenario = $this->makeBookingScenario();
        $scenario['franchise']->update(['platform_fee_percent' => null]);
        $booking = $this->completeBooking($scenario);

        $commission = app(CommissionService::class)->applyForBooking($booking);

        // 30% global default platform fee, franchise's 10% commission_value unchanged.
        $this->assertEquals(300.0, $commission->platform_commission);
        $this->assertEquals(100.0, $commission->franchise_commission);
        $this->assertEquals(600.0, $commission->provider_commission);
    }

    public function test_provider_negotiated_agreement_overrides_both_franchise_and_global_for_a_service_booking(): void
    {
        $scenario = $this->makeBookingScenario(); // franchise platform_fee_percent = 5
        ProviderCommissionAgreement::create(['provider_id' => $scenario['provider']->id, 'platform_fee_percent' => 40]);
        $booking = $this->completeBooking($scenario);

        $commission = app(CommissionService::class)->applyForBooking($booking);

        $this->assertEquals(400.0, $commission->platform_commission);
    }

    public function test_parcel_order_field_worker_uses_the_global_default_when_franchise_column_is_null(): void
    {
        // FieldWorker path — proves the null-safety fix applies uniformly
        // (fixing the same silent-0% bug for this vertical too) while the
        // split-calculation shape itself is untouched.
        $scenario = $this->makeParcelOrderScenario();
        $scenario['franchise']->update(['platform_fee_percent' => null]);
        $scenario['order']->update([
            'assigned_worker_id' => $scenario['rider']->id,
            'status' => 'delivered', 'price_final' => 200, 'payment_status' => 'paid',
        ]);

        $commission = app(CommissionService::class)->applyForParcelOrder($scenario['order']->fresh());

        // 30% of 200 = 60 platform; 10% franchise commission_value (default) = 20; rest to the rider.
        $this->assertEquals(60.0, $commission->platform_commission);
        $this->assertEquals(20.0, $commission->franchise_commission);
        $this->assertEquals(120.0, $commission->provider_commission);
    }

    public function test_parcel_order_field_worker_still_uses_the_configured_franchise_rate_when_present(): void
    {
        // Regression proof for the FieldWorker path — franchise.platform_fee_percent
        // = 5 (default), untouched, must resolve exactly as before.
        $scenario = $this->makeParcelOrderScenario();
        $scenario['order']->update([
            'assigned_worker_id' => $scenario['rider']->id,
            'status' => 'delivered', 'price_final' => 200, 'payment_status' => 'paid',
        ]);

        $commission = app(CommissionService::class)->applyForParcelOrder($scenario['order']->fresh());

        $this->assertEquals(10.0, $commission->platform_commission); // 5% of 200
    }
}
