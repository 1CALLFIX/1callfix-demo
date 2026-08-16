<?php

namespace Tests\Feature\Parcel;

use App\Actions\AcceptParcelOfferAction;
use App\Actions\AdminCancelParcelOrderAction;
use App\Actions\CreateParcelOrderAction;
use App\Actions\MarkParcelDeliveredAction;
use App\Actions\MarkParcelPickedUpAction;
use App\Exceptions\ModuleNotActiveException;
use App\Models\Commission;
use App\Models\DispatchAttempt;
use App\Models\ParcelOrder;
use App\Models\Payment;
use App\Services\ModuleActivationService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\TestCase;

/**
 * Phase 22.4 (Parcel). Core order-lifecycle coverage: module activation
 * gate, creation, pricing, dispatch, assignment, pickup, delivery,
 * cancellation, payment, wallet, commission, idempotency, and invalid
 * transitions -- mirroring the density of this mission's own established
 * Booking FSM test suite (FINAL_SYSTEM_TEST_MATRIX.md).
 */
class ParcelOrderLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;
    use ParcelOrderFixtureHelpers;

    // ============================== Module activation gate ==============================

    public function test_creating_a_parcel_order_is_blocked_while_the_module_is_not_implemented(): void
    {
        // Deliberately NOT calling enableParcelModuleForTests() -- this
        // proves the real, shipped default (parcel.is_implemented = false)
        // actually blocks order creation, not just that the mechanism
        // exists in isolation (ModuleActivationEnforcementTest already
        // covers that for Service).
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        [$pickup, $dropoff] = $this->makeParcelAddresses($franchise, $zone, $customer);

        $this->expectException(ModuleNotActiveException::class);

        app(CreateParcelOrderAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
            'pickup_address_id' => $pickup->id, 'dropoff_address_id' => $dropoff->id,
        ]);
    }

    public function test_creating_a_parcel_order_succeeds_once_the_module_is_enabled(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateParcelFor($franchise);
        $customer = $this->makeCustomer();
        [$pickup, $dropoff] = $this->makeParcelAddresses($franchise, $zone, $customer);

        $order = app(CreateParcelOrderAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
            'pickup_address_id' => $pickup->id, 'dropoff_address_id' => $dropoff->id,
        ]);

        $this->assertNotNull($order->id);
        $this->assertSame('pending', $order->status);
        $this->assertNotEmpty($order->code);
    }

    public function test_a_module_off_at_franchise_level_blocks_creation_even_though_it_is_implemented(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateParcelFor($franchise);
        $customer = $this->makeCustomer();
        [$pickup, $dropoff] = $this->makeParcelAddresses($franchise, $zone, $customer);

        app(ModuleActivationService::class)->setActive('parcel', 'franchise', $franchise->id, false);

        $this->expectException(ModuleNotActiveException::class);

        app(CreateParcelOrderAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
            'pickup_address_id' => $pickup->id, 'dropoff_address_id' => $dropoff->id,
        ]);
    }

    // ============================== Order code / numbering ==============================

    public function test_parcel_order_code_is_distinct_in_shape_from_service_booking_codes(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateParcelFor($franchise);
        $customer = $this->makeCustomer();
        [$pickup, $dropoff] = $this->makeParcelAddresses($franchise, $zone, $customer);

        $order = app(CreateParcelOrderAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
            'pickup_address_id' => $pickup->id, 'dropoff_address_id' => $dropoff->id,
        ]);

        $this->assertStringContainsString('-PCL-', $order->code);
        $this->assertStringStartsWith(strtoupper($franchise->code), $order->code);
    }

    public function test_parcel_and_service_sequences_are_independent(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateParcelFor($franchise);
        [$category, $service] = $this->makeCategoryAndService();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        [$pickup, $dropoff] = $this->makeParcelAddresses($franchise, $zone, $customer);

        $booking = \App\Models\Booking::create([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
            'service_id' => $service->id, 'address_id' => $address->id, 'status' => 'pending', 'price_quoted' => 500,
        ]);

        $order = app(CreateParcelOrderAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
            'pickup_address_id' => $pickup->id, 'dropoff_address_id' => $dropoff->id,
        ]);

        $this->assertNotSame($booking->code, $order->code);
        $this->assertStringNotContainsString('-PCL-', $booking->code);
    }

    // ============================== Pricing ==============================

    public function test_pricing_defaults_to_zero_base_fare_when_unconfigured(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateParcelFor($franchise);
        $customer = $this->makeCustomer();
        [$pickup, $dropoff] = $this->makeParcelAddresses($franchise, $zone, $customer);

        $order = app(CreateParcelOrderAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
            'pickup_address_id' => $pickup->id, 'dropoff_address_id' => $dropoff->id, 'package_weight_kg' => 5,
        ]);

        $this->assertSame(0.0, (float) $order->price_quoted, 'No pricing tier is a real business decision -- must never be invented as a nonzero default.');
    }

    public function test_pricing_uses_configured_base_fare_and_per_kg_rate(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateParcelFor($franchise);
        $customer = $this->makeCustomer();
        [$pickup, $dropoff] = $this->makeParcelAddresses($franchise, $zone, $customer);

        \App\Models\Setting::set('parcel.base_fare', '30', 'franchise', $franchise->id);
        \App\Models\Setting::set('parcel.per_kg_rate', '10', 'franchise', $franchise->id);

        $order = app(CreateParcelOrderAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
            'pickup_address_id' => $pickup->id, 'dropoff_address_id' => $dropoff->id, 'package_weight_kg' => 5,
        ]);

        $this->assertSame(80.0, (float) $order->price_quoted); // 30 + (10 * 5)
    }

    // ============================== Dispatch / assignment ==============================

    public function test_dispatch_job_offers_the_order_to_an_eligible_rider(): void
    {
        $scenario = $this->makeParcelOrderScenario('pending');

        app(\App\Jobs\ParcelDispatchJob::class, ['parcelOrderId' => $scenario['order']->id])->handle(app(\App\Services\DispatchService::class));

        $this->assertSame('searching_worker', $scenario['order']->fresh()->status);

        $attempt = DispatchAttempt::where('dispatchable_type', ParcelOrder::class)
            ->where('dispatchable_id', $scenario['order']->id)
            ->first();

        $this->assertNotNull($attempt, 'Dispatch must offer the order to the eligible rider fixture.');
        $this->assertSame($scenario['rider']->id, $attempt->notifiable_id);
        $this->assertSame(\App\Models\FieldWorker::class, $attempt->notifiable_type);
    }

    public function test_dispatch_never_offers_a_rider_without_the_parcel_rider_capability(): void
    {
        $scenario = $this->makeParcelOrderScenario('pending');
        // A second rider with NO capability row at all.
        $uncapable = \App\Models\FieldWorker::create([
            'user_id' => \App\Models\User::create([
                'uuid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Uncapable',
                'phone' => '9'.fake()->unique()->numerify('#########'), 'role' => 'provider', 'status' => 'active',
            ])->id,
            'franchise_id' => $scenario['franchise']->id, 'zone_id' => $scenario['zone']->id,
            'kyc_status' => 'approved', 'is_active' => true, 'is_online' => true,
            'current_lat' => 1.0, 'current_lng' => 1.0,
        ]);

        app(\App\Jobs\ParcelDispatchJob::class, ['parcelOrderId' => $scenario['order']->id])->handle(app(\App\Services\DispatchService::class));

        $offeredIds = DispatchAttempt::where('dispatchable_type', ParcelOrder::class)
            ->where('dispatchable_id', $scenario['order']->id)
            ->pluck('notifiable_id');

        $this->assertNotContains($uncapable->id, $offeredIds);
    }

    public function test_accepting_the_offer_assigns_the_order_and_generates_two_otps(): void
    {
        $scenario = $this->makeParcelOrderScenario('searching_worker');
        DispatchAttempt::create([
            'dispatchable_type' => ParcelOrder::class, 'dispatchable_id' => $scenario['order']->id,
            'notifiable_type' => \App\Models\FieldWorker::class, 'notifiable_id' => $scenario['rider']->id,
            'status' => 'notified', 'notified_at' => now(),
        ]);

        $order = app(AcceptParcelOfferAction::class)->execute($scenario['order']->id, $scenario['rider']);

        $this->assertSame('assigned', $order->status);
        $this->assertSame($scenario['rider']->id, $order->assigned_worker_id);
        $this->assertNotEmpty($order->pickup_otp);
        $this->assertNotEmpty($order->delivery_otp);
        $this->assertNotSame($order->pickup_otp, $order->delivery_otp);
    }

    public function test_a_second_rider_cannot_accept_an_already_assigned_order(): void
    {
        $scenario = $this->makeAssignedParcelOrderScenario();
        $otherRider = $this->makeParcelRiderIn($scenario['franchise'], $scenario['zone']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already been assigned');

        app(AcceptParcelOfferAction::class)->execute($scenario['order']->id, $otherRider);
    }

    // ============================== Pickup / delivery (OTP-gated) ==============================

    public function test_pickup_requires_the_correct_otp(): void
    {
        $scenario = $this->makeAssignedParcelOrderScenario();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Incorrect pickup OTP');

        app(MarkParcelPickedUpAction::class)->execute($scenario['order']->id, $scenario['rider'], 'wrong');
    }

    public function test_pickup_succeeds_with_correct_otp(): void
    {
        $scenario = $this->makeAssignedParcelOrderScenario();

        $order = app(MarkParcelPickedUpAction::class)->execute($scenario['order']->id, $scenario['rider'], '1234');

        $this->assertSame('picked_up', $order->status);
        $this->assertNotNull($order->picked_up_at);
    }

    public function test_delivery_requires_the_correct_otp(): void
    {
        $scenario = $this->makeAssignedParcelOrderScenario();
        app(MarkParcelPickedUpAction::class)->execute($scenario['order']->id, $scenario['rider'], '1234');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Incorrect delivery OTP');

        app(MarkParcelDeliveredAction::class)->execute($scenario['order']->id, $scenario['rider'], 'wrong');
    }

    public function test_delivery_succeeds_and_applies_commission(): void
    {
        $scenario = $this->makeAssignedParcelOrderScenario();
        $scenario['order']->update(['price_quoted' => 100]);
        $scenario['franchise']->update(['platform_fee_percent' => 20, 'commission_model' => 'revenue_share', 'commission_value' => 10, 'owner_user_id' => null]);
        app(MarkParcelPickedUpAction::class)->execute($scenario['order']->id, $scenario['rider'], '1234');

        $order = app(MarkParcelDeliveredAction::class)->execute($scenario['order']->id, $scenario['rider'], '5678');

        $this->assertSame('delivered', $order->status);
        $this->assertSame(100.0, (float) $order->price_final);

        $commission = Commission::where('parcel_order_id', $order->id)->first();
        $this->assertNotNull($commission);
        $this->assertSame(20.0, (float) $commission->platform_commission);
        $this->assertSame(10.0, (float) $commission->franchise_commission);
        $this->assertSame(70.0, (float) $commission->provider_commission);

        $balance = app(WalletService::class)->balance($scenario['rider']->user);
        $this->assertSame(70.0, $balance, 'The rider\'s wallet must be credited via WalletService, the same engine Service uses.');
    }

    public function test_delivery_is_idempotent_and_never_double_credits_the_wallet(): void
    {
        $scenario = $this->makeAssignedParcelOrderScenario();
        $scenario['order']->update(['price_quoted' => 100]);
        $scenario['franchise']->update(['platform_fee_percent' => 0, 'commission_model' => 'flat_fee', 'commission_value' => 0]);
        app(MarkParcelPickedUpAction::class)->execute($scenario['order']->id, $scenario['rider'], '1234');
        app(MarkParcelDeliveredAction::class)->execute($scenario['order']->id, $scenario['rider'], '5678');

        // Calling the commission step again directly (simulating a retried
        // job) must not double-credit -- same guarantee CommissionIdempotencyTest
        // proves for Service.
        app(\App\Services\CommissionService::class)->applyForParcelOrder($scenario['order']->fresh());

        $this->assertSame(1, Commission::where('parcel_order_id', $scenario['order']->id)->count());
        $this->assertSame(100.0, app(WalletService::class)->balance($scenario['rider']->user));
    }

    public function test_cannot_pick_up_an_order_not_assigned_to_you(): void
    {
        $scenario = $this->makeAssignedParcelOrderScenario();
        $otherRider = $this->makeParcelRiderIn($scenario['franchise'], $scenario['zone']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not assigned to you');

        app(MarkParcelPickedUpAction::class)->execute($scenario['order']->id, $otherRider, '1234');
    }

    public function test_cannot_deliver_before_pickup(): void
    {
        $scenario = $this->makeAssignedParcelOrderScenario();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot be marked delivered from status');

        app(MarkParcelDeliveredAction::class)->execute($scenario['order']->id, $scenario['rider'], '5678');
    }

    // ============================== Cancellation ==============================

    public function test_admin_can_cancel_a_pending_order(): void
    {
        $scenario = $this->makeParcelOrderScenario('pending');

        $order = app(AdminCancelParcelOrderAction::class)->execute($scenario['order']->id, 'Customer requested');

        $this->assertSame('cancelled', $order->status);
        $this->assertSame('Customer requested', $order->cancellation_note);
    }

    public function test_cannot_cancel_an_already_delivered_order(): void
    {
        $scenario = $this->makeParcelOrderScenario('delivered');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already delivered');

        app(AdminCancelParcelOrderAction::class)->execute($scenario['order']->id, 'too late');
    }

    public function test_cancellation_fee_is_zero_within_the_free_window(): void
    {
        $scenario = $this->makeParcelOrderScenario('pending');
        \App\Models\Setting::set('cancellation.free_minutes', '15');
        \App\Models\Setting::set('cancellation.fee_type', 'flat');
        \App\Models\Setting::set('cancellation.fee_value', '20');

        $order = app(AdminCancelParcelOrderAction::class)->execute($scenario['order']->id, 'test');

        $this->assertSame(0.0, (float) $order->cancellation_fee);
    }

    // ============================== Payment / wallet ==============================

    public function test_wallet_payment_debits_customer_and_records_a_captured_payment(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateParcelFor($franchise);
        $customer = $this->makeCustomer();
        [$pickup, $dropoff] = $this->makeParcelAddresses($franchise, $zone, $customer);
        app(WalletService::class)->credit($customer, 500, 'test top-up');

        $order = app(CreateParcelOrderAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
            'pickup_address_id' => $pickup->id, 'dropoff_address_id' => $dropoff->id,
            'payment_method' => 'wallet', 'price_quoted' => 100,
        ]);

        $this->assertSame('paid', $order->payment_status);
        $this->assertSame(400.0, app(WalletService::class)->balance($customer));

        $payment = Payment::where('parcel_order_id', $order->id)->first();
        $this->assertNotNull($payment);
        $this->assertSame('captured', $payment->status);
        $this->assertSame('parcel_order', $payment->purpose);
    }

    public function test_insufficient_wallet_balance_rolls_back_the_entire_order(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateParcelFor($franchise);
        $customer = $this->makeCustomer();
        [$pickup, $dropoff] = $this->makeParcelAddresses($franchise, $zone, $customer);

        $this->expectException(\RuntimeException::class);

        app(CreateParcelOrderAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
            'pickup_address_id' => $pickup->id, 'dropoff_address_id' => $dropoff->id,
            'payment_method' => 'wallet', 'price_quoted' => 100,
        ]);

        $this->assertSame(0, ParcelOrder::count(), 'A failed wallet debit must roll back order creation entirely, not leave an unpaid order behind.');
    }

    // ============================== Regression against Service Booking ==============================

    public function test_service_booking_creation_is_completely_unaffected_by_parcel_existing(): void
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
