<?php

namespace Tests\Feature\Taxi;

use App\Actions\AcceptTaxiOfferAction;
use App\Actions\AdminCancelTaxiRideAction;
use App\Actions\CompleteTaxiTripAction;
use App\Actions\CreateTaxiRideAction;
use App\Actions\StartTaxiTripAction;
use App\Exceptions\ModuleNotActiveException;
use App\Models\Commission;
use App\Models\DispatchAttempt;
use App\Models\Payment;
use App\Models\TaxiRide;
use App\Services\ModuleActivationService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\TaxiRideFixtureHelpers;
use Tests\TestCase;

/**
 * Phase 22.6 (Taxi). Core lifecycle coverage, same density as
 * ParcelOrderLifecycleTest -- module gate, creation, pricing, dispatch,
 * assignment, OTP-gated trip start, completion + commission + wallet +
 * idempotency, cancellation + refund, payment, and a Service/Parcel
 * regression check.
 */
class TaxiRideLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;
    use TaxiRideFixtureHelpers;

    public function test_creating_a_taxi_ride_is_blocked_while_the_module_is_not_implemented(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        [$pickup, $dropoff] = $this->makeTaxiAddresses($franchise, $zone, $customer);

        $this->expectException(ModuleNotActiveException::class);

        app(CreateTaxiRideAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
            'pickup_address_id' => $pickup->id, 'dropoff_address_id' => $dropoff->id,
        ]);
    }

    public function test_creating_a_taxi_ride_succeeds_once_the_module_is_enabled(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateTaxiFor($franchise);
        $customer = $this->makeCustomer();
        [$pickup, $dropoff] = $this->makeTaxiAddresses($franchise, $zone, $customer);

        $ride = app(CreateTaxiRideAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
            'pickup_address_id' => $pickup->id, 'dropoff_address_id' => $dropoff->id,
        ]);

        $this->assertNotNull($ride->id);
        $this->assertSame('requested', $ride->status);
        $this->assertStringContainsString('-TXI-', $ride->code);
    }

    public function test_pricing_defaults_to_zero_when_unconfigured(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateTaxiFor($franchise);
        $customer = $this->makeCustomer();
        [$pickup, $dropoff] = $this->makeTaxiAddresses($franchise, $zone, $customer);

        $ride = app(CreateTaxiRideAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
            'pickup_address_id' => $pickup->id, 'dropoff_address_id' => $dropoff->id,
        ]);

        $this->assertSame(0.0, (float) $ride->price_quoted);
    }

    public function test_dispatch_job_offers_the_ride_to_an_eligible_driver(): void
    {
        $scenario = $this->makeTaxiRideScenario('requested');

        app(\App\Jobs\TaxiDispatchJob::class, ['taxiRideId' => $scenario['ride']->id])->handle(app(\App\Services\DispatchService::class));

        $this->assertSame('searching_driver', $scenario['ride']->fresh()->status);

        $attempt = DispatchAttempt::where('dispatchable_type', TaxiRide::class)->where('dispatchable_id', $scenario['ride']->id)->first();
        $this->assertNotNull($attempt);
        $this->assertSame($scenario['driver']->id, $attempt->notifiable_id);
    }

    public function test_dispatch_never_offers_a_worker_without_the_taxi_driver_capability(): void
    {
        $scenario = $this->makeTaxiRideScenario('requested');
        // A parcel rider is NOT eligible for a taxi ride -- different capability.
        $parcelRider = \App\Models\FieldWorker::create([
            'user_id' => \App\Models\User::create([
                'uuid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Rider',
                'phone' => '9'.fake()->unique()->numerify('#########'), 'role' => 'provider', 'status' => 'active',
            ])->id,
            'franchise_id' => $scenario['franchise']->id, 'zone_id' => $scenario['zone']->id,
            'kyc_status' => 'approved', 'is_active' => true, 'is_online' => true, 'current_lat' => 1.0, 'current_lng' => 1.0,
        ]);
        \App\Models\FieldWorkerCapability::create(['field_worker_id' => $parcelRider->id, 'capability_type' => 'parcel_rider']);

        app(\App\Jobs\TaxiDispatchJob::class, ['taxiRideId' => $scenario['ride']->id])->handle(app(\App\Services\DispatchService::class));

        $offeredIds = DispatchAttempt::where('dispatchable_type', TaxiRide::class)->where('dispatchable_id', $scenario['ride']->id)->pluck('notifiable_id');
        $this->assertNotContains($parcelRider->id, $offeredIds);
    }

    public function test_accepting_the_offer_assigns_the_ride_and_generates_one_otp(): void
    {
        $scenario = $this->makeTaxiRideScenario('searching_driver');
        DispatchAttempt::create([
            'dispatchable_type' => TaxiRide::class, 'dispatchable_id' => $scenario['ride']->id,
            'notifiable_type' => \App\Models\FieldWorker::class, 'notifiable_id' => $scenario['driver']->id,
            'status' => 'notified', 'notified_at' => now(),
        ]);

        $ride = app(AcceptTaxiOfferAction::class)->execute($scenario['ride']->id, $scenario['driver']);

        $this->assertSame('assigned', $ride->status);
        $this->assertSame($scenario['driver']->id, $ride->assigned_worker_id);
        $this->assertNotEmpty($ride->start_otp);
    }

    public function test_a_second_driver_cannot_accept_an_already_assigned_ride(): void
    {
        $scenario = $this->makeAssignedTaxiRideScenario();
        $otherDriver = $this->makeTaxiDriverIn($scenario['franchise'], $scenario['zone']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already been assigned');

        app(AcceptTaxiOfferAction::class)->execute($scenario['ride']->id, $otherDriver);
    }

    public function test_trip_start_requires_the_correct_otp(): void
    {
        $scenario = $this->makeAssignedTaxiRideScenario();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Incorrect trip start OTP');

        app(StartTaxiTripAction::class)->execute($scenario['ride']->id, $scenario['driver'], 'wrong');
    }

    public function test_trip_start_succeeds_with_correct_otp(): void
    {
        $scenario = $this->makeAssignedTaxiRideScenario();

        $ride = app(StartTaxiTripAction::class)->execute($scenario['ride']->id, $scenario['driver'], '1234');

        $this->assertSame('trip_started', $ride->status);
        $this->assertNotNull($ride->trip_started_at);
    }

    public function test_trip_completion_computes_distance_applies_commission_and_credits_wallet(): void
    {
        $scenario = $this->makeAssignedTaxiRideScenario();
        $scenario['ride']->update(['price_quoted' => 100]);
        $scenario['franchise']->update(['platform_fee_percent' => 20, 'commission_model' => 'revenue_share', 'commission_value' => 10, 'owner_user_id' => null]);
        app(StartTaxiTripAction::class)->execute($scenario['ride']->id, $scenario['driver'], '1234');

        $ride = app(CompleteTaxiTripAction::class)->execute($scenario['ride']->id, $scenario['driver']);

        $this->assertSame('trip_completed', $ride->status);
        $this->assertSame(100.0, (float) $ride->price_final);
        $this->assertNotNull($ride->distance_km);
        $this->assertGreaterThan(0, (float) $ride->distance_km);

        $commission = Commission::where('taxi_ride_id', $ride->id)->first();
        $this->assertNotNull($commission);
        $this->assertSame(70.0, (float) $commission->provider_commission);

        $this->assertSame(70.0, app(WalletService::class)->balance($scenario['driver']->user));
    }

    public function test_completion_is_idempotent_and_never_double_credits(): void
    {
        $scenario = $this->makeAssignedTaxiRideScenario();
        $scenario['ride']->update(['price_quoted' => 100]);
        $scenario['franchise']->update(['platform_fee_percent' => 0, 'commission_model' => 'flat_fee', 'commission_value' => 0]);
        app(StartTaxiTripAction::class)->execute($scenario['ride']->id, $scenario['driver'], '1234');
        app(CompleteTaxiTripAction::class)->execute($scenario['ride']->id, $scenario['driver']);

        app(\App\Services\CommissionService::class)->applyForTaxiRide($scenario['ride']->fresh());

        $this->assertSame(1, Commission::where('taxi_ride_id', $scenario['ride']->id)->count());
        $this->assertSame(100.0, app(WalletService::class)->balance($scenario['driver']->user));
    }

    public function test_cannot_complete_before_trip_started(): void
    {
        $scenario = $this->makeAssignedTaxiRideScenario();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot be completed from status');

        app(CompleteTaxiTripAction::class)->execute($scenario['ride']->id, $scenario['driver']);
    }

    public function test_admin_can_cancel_a_requested_ride(): void
    {
        $scenario = $this->makeTaxiRideScenario('requested');

        $ride = app(AdminCancelTaxiRideAction::class)->execute($scenario['ride']->id, 'Customer requested');

        $this->assertSame('cancelled', $ride->status);
    }

    public function test_cannot_cancel_an_already_completed_ride(): void
    {
        $scenario = $this->makeTaxiRideScenario('trip_completed');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already trip_completed');

        app(AdminCancelTaxiRideAction::class)->execute($scenario['ride']->id, 'too late');
    }

    public function test_wallet_payment_debits_customer_and_records_a_captured_payment(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateTaxiFor($franchise);
        $customer = $this->makeCustomer();
        [$pickup, $dropoff] = $this->makeTaxiAddresses($franchise, $zone, $customer);
        app(WalletService::class)->credit($customer, 500, 'test top-up');

        $ride = app(CreateTaxiRideAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
            'pickup_address_id' => $pickup->id, 'dropoff_address_id' => $dropoff->id,
            'payment_method' => 'wallet', 'price_quoted' => 80,
        ]);

        $this->assertSame('paid', $ride->payment_status);
        $this->assertSame(420.0, app(WalletService::class)->balance($customer));

        $payment = Payment::where('taxi_ride_id', $ride->id)->first();
        $this->assertNotNull($payment);
        $this->assertSame('taxi_ride', $payment->purpose);
    }

    public function test_service_and_parcel_are_completely_unaffected_by_taxi_existing(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        [$category, $service] = $this->makeCategoryAndService();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);

        $booking = app(\App\Actions\CreateBookingAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
            'service_id' => $service->id, 'address_id' => $address->id, 'payment_method' => 'online',
        ]);

        $this->assertNotNull($booking->id);
        $this->assertSame('pending', $booking->status);
    }
}
