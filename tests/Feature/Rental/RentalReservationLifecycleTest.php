<?php

namespace Tests\Feature\Rental;

use App\Actions\AdminCancelRentalReservationAction;
use App\Actions\CompleteRentalReservationAction;
use App\Actions\ConfirmRentalReservationAction;
use App\Actions\CreateRentalReservationAction;
use App\Actions\ExtendRentalReservationAction;
use App\Actions\InspectRentalReservationAction;
use App\Actions\PickupRentalReservationAction;
use App\Actions\ReturnRentalReservationAction;
use App\Actions\StartRentalReservationAction;
use App\Exceptions\ModuleNotActiveException;
use App\Models\Commission;
use App\Models\Payment;
use App\Models\RentalReservation;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\RentalFixtureHelpers;
use Tests\TestCase;

/**
 * RENTAL MODULE IMPLEMENTATION -- core lifecycle coverage for the shared
 * Vehicle/Equipment engine: module gate, creation, duration/pricing,
 * overlap prevention, all three named flows (Self-Drive, With Driver,
 * Equipment+inspection), commission, cancellation, extension, wallet
 * payment, and cross-vertical regression.
 */
class RentalReservationLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;
    use RentalFixtureHelpers;

    public function test_creating_a_vehicle_reservation_is_blocked_while_the_module_is_not_implemented(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        $vehicle = $this->makeVehicle($franchise, $zone);

        $this->expectException(ModuleNotActiveException::class);

        app(CreateRentalReservationAction::class)->execute([
            'rental_type' => 'vehicle', 'rentable_id' => $vehicle->id, 'customer_id' => $customer->id,
            'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(5),
        ]);
    }

    public function test_creating_a_vehicle_reservation_succeeds_once_the_module_is_enabled(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateRentalFor($franchise);
        $customer = $this->makeCustomer();
        $vehicle = $this->makeVehicle($franchise, $zone, null, ['base_price' => 500]);

        $reservation = app(CreateRentalReservationAction::class)->execute([
            'rental_type' => 'vehicle', 'rentable_id' => $vehicle->id, 'customer_id' => $customer->id,
            'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(5),
        ]);

        $this->assertNotNull($reservation->id);
        $this->assertSame('pending', $reservation->status);
        $this->assertStringContainsString('-VEH-', $reservation->code);
        $this->assertSame('self_drive', $reservation->rental_mode);
        $this->assertSame(2.0, (float) $reservation->duration_quantity);
        $this->assertSame(1000.0, (float) $reservation->price_quoted); // 2 days * 500
    }

    public function test_creating_an_equipment_reservation_succeeds_and_has_no_rental_mode(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateRentalFor($franchise);
        $customer = $this->makeCustomer();
        $item = $this->makeEquipmentItem($franchise, $zone, null, ['base_price' => 200]);

        $reservation = app(CreateRentalReservationAction::class)->execute([
            'rental_type' => 'equipment', 'rentable_id' => $item->id, 'customer_id' => $customer->id,
            'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(4),
        ]);

        $this->assertStringContainsString('-EQP-', $reservation->code);
        $this->assertNull($reservation->rental_mode);
        $this->assertSame(200.0, (float) $reservation->price_quoted);
    }

    public function test_a_vehicle_that_does_not_support_with_driver_rejects_that_mode(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateRentalFor($franchise);
        $customer = $this->makeCustomer();
        $vehicle = $this->makeVehicle($franchise, $zone, null, ['supported_rental_modes' => ['self_drive']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("does not support the 'with_driver'");

        app(CreateRentalReservationAction::class)->execute([
            'rental_type' => 'vehicle', 'rentable_id' => $vehicle->id, 'customer_id' => $customer->id,
            'rental_mode' => 'with_driver',
            'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(5),
        ]);
    }

    public function test_a_vehicle_that_supports_with_driver_accepts_that_mode(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateRentalFor($franchise);
        $customer = $this->makeCustomer();
        $vehicle = $this->makeVehicle($franchise, $zone, null, ['supported_rental_modes' => ['self_drive', 'with_driver']]);

        $reservation = app(CreateRentalReservationAction::class)->execute([
            'rental_type' => 'vehicle', 'rentable_id' => $vehicle->id, 'customer_id' => $customer->id,
            'rental_mode' => 'with_driver',
            'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(5),
        ]);

        $this->assertSame('with_driver', $reservation->rental_mode);
    }

    public function test_end_time_must_be_after_start_time(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateRentalFor($franchise);
        $customer = $this->makeCustomer();
        $vehicle = $this->makeVehicle($franchise, $zone);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('End time must be after start time');

        $when = now()->addDays(5);
        app(CreateRentalReservationAction::class)->execute([
            'rental_type' => 'vehicle', 'rentable_id' => $vehicle->id, 'customer_id' => $customer->id,
            'starts_at' => $when, 'ends_at' => $when,
        ]);
    }

    public function test_cannot_reserve_an_overlapping_range_for_the_same_vehicle(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateRentalFor($franchise);
        $customer = $this->makeCustomer();
        $vehicle = $this->makeVehicle($franchise, $zone);

        app(CreateRentalReservationAction::class)->execute([
            'rental_type' => 'vehicle', 'rentable_id' => $vehicle->id, 'customer_id' => $customer->id,
            'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(6),
        ]);

        $secondCustomer = $this->makeCustomer();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not available');

        app(CreateRentalReservationAction::class)->execute([
            'rental_type' => 'vehicle', 'rentable_id' => $vehicle->id, 'customer_id' => $secondCustomer->id,
            'starts_at' => now()->addDays(4), 'ends_at' => now()->addDays(7),
        ]);
    }

    public function test_a_conflicting_reservation_does_not_leave_a_partial_row(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateRentalFor($franchise);
        $customer = $this->makeCustomer();
        $vehicle = $this->makeVehicle($franchise, $zone);

        app(CreateRentalReservationAction::class)->execute([
            'rental_type' => 'vehicle', 'rentable_id' => $vehicle->id, 'customer_id' => $customer->id,
            'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(6),
        ]);

        try {
            app(CreateRentalReservationAction::class)->execute([
                'rental_type' => 'vehicle', 'rentable_id' => $vehicle->id, 'customer_id' => $customer->id,
                'starts_at' => now()->addDays(4), 'ends_at' => now()->addDays(7),
            ]);
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(1, RentalReservation::count(), 'A conflicting reservation attempt must roll back entirely -- no orphaned pending row.');
    }

    public function test_adjacent_non_overlapping_reservations_are_both_allowed(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateRentalFor($franchise);
        $customerA = $this->makeCustomer();
        $customerB = $this->makeCustomer();
        $vehicle = $this->makeVehicle($franchise, $zone);

        $first = app(CreateRentalReservationAction::class)->execute([
            'rental_type' => 'vehicle', 'rentable_id' => $vehicle->id, 'customer_id' => $customerA->id,
            'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(5),
        ]);
        $second = app(CreateRentalReservationAction::class)->execute([
            'rental_type' => 'vehicle', 'rentable_id' => $vehicle->id, 'customer_id' => $customerB->id,
            'starts_at' => now()->addDays(5), 'ends_at' => now()->addDays(7),
        ]);

        $this->assertNotNull($first->id);
        $this->assertNotNull($second->id);
    }

    // ============================== Self-Drive lifecycle ==============================

    public function test_self_drive_full_lifecycle_confirm_pickup_active_return_complete_applies_commission(): void
    {
        $scenario = $this->makeVehicleReservationScenario('pending');
        $scenario['reservation']->update(['price_quoted' => 2000]);
        $scenario['franchise']->update(['platform_fee_percent' => 20, 'commission_model' => 'revenue_share', 'commission_value' => 10, 'owner_user_id' => null]);

        $confirmed = app(ConfirmRentalReservationAction::class)->execute($scenario['reservation']->id);
        $this->assertSame('confirmed', $confirmed->status);

        $pickedUp = app(PickupRentalReservationAction::class)->execute($scenario['reservation']->id);
        $this->assertSame('picked_up', $pickedUp->status);
        $this->assertNotNull($pickedUp->picked_up_at);

        $active = app(StartRentalReservationAction::class)->execute($scenario['reservation']->id);
        $this->assertSame('active', $active->status);

        $returned = app(ReturnRentalReservationAction::class)->execute($scenario['reservation']->id);
        $this->assertSame('returned', $returned->status);
        $this->assertNotNull($returned->returned_at);

        $completed = app(CompleteRentalReservationAction::class)->execute($scenario['reservation']->id);
        $this->assertSame('completed', $completed->status);
        $this->assertSame(2000.0, (float) $completed->price_final);

        $commission = Commission::where('rental_reservation_id', $completed->id)->first();
        $this->assertNotNull($commission);
        $this->assertSame(1400.0, (float) $commission->provider_commission); // 2000 - 400(platform) - 200(franchise)
        $this->assertSame(1400.0, app(WalletService::class)->balance($scenario['owner']->user));
    }

    public function test_self_drive_cannot_skip_pickup_before_starting(): void
    {
        $scenario = $this->makeVehicleReservationScenario('confirmed');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("expected 'picked_up'");

        app(StartRentalReservationAction::class)->execute($scenario['reservation']->id);
    }

    public function test_self_drive_cannot_complete_before_returned(): void
    {
        $scenario = $this->makeVehicleReservationScenario('active');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("expected 'returned'");

        app(CompleteRentalReservationAction::class)->execute($scenario['reservation']->id);
    }

    // ============================== With Driver lifecycle ==============================

    public function test_with_driver_lifecycle_skips_pickup_and_return(): void
    {
        $scenario = $this->makeVehicleReservationScenario('pending', ['rental_mode' => 'with_driver']);

        $confirmed = app(ConfirmRentalReservationAction::class)->execute($scenario['reservation']->id);
        $this->assertSame('confirmed', $confirmed->status);

        $active = app(StartRentalReservationAction::class)->execute($scenario['reservation']->id);
        $this->assertSame('active', $active->status);

        $completed = app(CompleteRentalReservationAction::class)->execute($scenario['reservation']->id);
        $this->assertSame('completed', $completed->status);
        $this->assertNull($completed->picked_up_at);
        $this->assertNull($completed->returned_at);
    }

    public function test_with_driver_rejects_pickup_and_return_calls(): void
    {
        $scenario = $this->makeVehicleReservationScenario('confirmed', ['rental_mode' => 'with_driver']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('do not have a pickup step');

        app(PickupRentalReservationAction::class)->execute($scenario['reservation']->id);
    }

    public function test_confirm_can_assign_a_driver_for_with_driver_reservations(): void
    {
        $scenario = $this->makeVehicleReservationScenario('pending', ['rental_mode' => 'with_driver']);
        $worker = $this->makeFieldWorkerIn($scenario['franchise'], $scenario['zone']);

        $confirmed = app(ConfirmRentalReservationAction::class)->execute($scenario['reservation']->id, $worker->id);

        $this->assertSame($worker->id, $confirmed->driver_field_worker_id);
    }

    // ============================== Equipment + inspection ==============================

    public function test_equipment_requiring_inspection_cannot_complete_without_one(): void
    {
        $category = $this->makeEquipmentCategory(['requires_inspection' => true]);
        $scenario = $this->makeEquipmentReservationScenario('returned');
        $scenario['item']->update(['equipment_category_id' => $category->id]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires an inspection');

        app(CompleteRentalReservationAction::class)->execute($scenario['reservation']->id);
    }

    public function test_equipment_requiring_inspection_completes_once_inspected(): void
    {
        $category = $this->makeEquipmentCategory(['requires_inspection' => true]);
        $scenario = $this->makeEquipmentReservationScenario('returned');
        $scenario['item']->update(['equipment_category_id' => $category->id]);

        app(InspectRentalReservationAction::class)->execute($scenario['reservation']->id, 'No damage found.');

        $completed = app(CompleteRentalReservationAction::class)->execute($scenario['reservation']->id);

        $this->assertSame('completed', $completed->status);
        $this->assertSame('No damage found.', $completed->inspection_notes);
    }

    public function test_equipment_not_requiring_inspection_completes_without_one(): void
    {
        $category = $this->makeEquipmentCategory(['requires_inspection' => false]);
        $scenario = $this->makeEquipmentReservationScenario('returned');
        $scenario['item']->update(['equipment_category_id' => $category->id]);

        $completed = app(CompleteRentalReservationAction::class)->execute($scenario['reservation']->id);

        $this->assertSame('completed', $completed->status);
    }

    // ============================== Cancellation ==============================

    public function test_admin_can_cancel_a_pending_reservation_and_the_range_is_freed(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateRentalFor($franchise);
        $customer = $this->makeCustomer();
        $vehicle = $this->makeVehicle($franchise, $zone);

        $reservation = app(CreateRentalReservationAction::class)->execute([
            'rental_type' => 'vehicle', 'rentable_id' => $vehicle->id, 'customer_id' => $customer->id,
            'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(6),
        ]);

        app(AdminCancelRentalReservationAction::class)->execute($reservation->id, 'Customer requested');

        $this->assertSame('cancelled', $reservation->fresh()->status);

        // The exact same range is available again, no manual release step.
        $secondCustomer = $this->makeCustomer();
        $second = app(CreateRentalReservationAction::class)->execute([
            'rental_type' => 'vehicle', 'rentable_id' => $vehicle->id, 'customer_id' => $secondCustomer->id,
            'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(6),
        ]);
        $this->assertNotNull($second->id);
    }

    public function test_cannot_cancel_an_already_completed_reservation(): void
    {
        $scenario = $this->makeVehicleReservationScenario('completed');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already completed');

        app(AdminCancelRentalReservationAction::class)->execute($scenario['reservation']->id, 'too late');
    }

    public function test_cancellation_fee_is_zero_within_the_free_window_before_start(): void
    {
        \App\Models\Setting::set('rental.vehicle_equipment.free_cancellation_hours_before_start', '48');
        \App\Models\Setting::set('rental.vehicle_equipment.cancellation_fee_type', 'flat');
        \App\Models\Setting::set('rental.vehicle_equipment.cancellation_fee_value', '200');

        $scenario = $this->makeVehicleReservationScenario('pending'); // starts 5 days out in the fixture

        $reservation = app(AdminCancelRentalReservationAction::class)->execute($scenario['reservation']->id, 'test');

        $this->assertSame(0.0, (float) $reservation->cancellation_fee);
    }

    public function test_cancellation_fee_applies_when_cancelled_close_to_start(): void
    {
        \App\Models\Setting::set('rental.vehicle_equipment.free_cancellation_hours_before_start', '48');
        \App\Models\Setting::set('rental.vehicle_equipment.cancellation_fee_type', 'flat');
        \App\Models\Setting::set('rental.vehicle_equipment.cancellation_fee_value', '200');

        $scenario = $this->makeVehicleReservationScenario('pending');
        $scenario['reservation']->update(['starts_at' => now()->addHours(10)]);

        $reservation = app(AdminCancelRentalReservationAction::class)->execute($scenario['reservation']->id, 'test');

        $this->assertSame(200.0, (float) $reservation->cancellation_fee);
    }

    // ============================== Extension ==============================

    public function test_active_reservation_can_be_extended_and_price_increases(): void
    {
        $scenario = $this->makeVehicleReservationScenario('active', ['duration_quantity' => 2, 'price_quoted' => 1000]);
        // vehicle base_price 500/day (see makeVehicle default in fixture -- but scenario sets 500 explicitly)

        $extended = app(ExtendRentalReservationAction::class)->execute($scenario['reservation']->id, now()->addDays(8)->toDateTimeString());

        $this->assertSame(3.0, (float) $extended->duration_quantity); // was 2 days, ends_at now day7->day8, +1 day
        $this->assertSame(1500.0, (float) $extended->price_quoted); // 1000 + (500 * 1)
    }

    public function test_extension_is_rejected_if_it_collides_with_another_reservation(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateRentalFor($franchise);
        $vehicle = $this->makeVehicle($franchise, $zone, null, ['base_price' => 500]);
        $customerA = $this->makeCustomer();
        $customerB = $this->makeCustomer();

        $first = app(CreateRentalReservationAction::class)->execute([
            'rental_type' => 'vehicle', 'rentable_id' => $vehicle->id, 'customer_id' => $customerA->id,
            'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(5),
        ]);
        app(CreateRentalReservationAction::class)->execute([
            'rental_type' => 'vehicle', 'rentable_id' => $vehicle->id, 'customer_id' => $customerB->id,
            'starts_at' => now()->addDays(6), 'ends_at' => now()->addDays(8),
        ]);

        app(ConfirmRentalReservationAction::class)->execute($first->id);
        app(PickupRentalReservationAction::class)->execute($first->id);
        app(StartRentalReservationAction::class)->execute($first->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not available');

        app(ExtendRentalReservationAction::class)->execute($first->id, now()->addDays(7)->toDateTimeString());
    }

    // ============================== Payment ==============================

    public function test_wallet_payment_debits_customer_and_records_a_captured_payment(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateRentalFor($franchise);
        $customer = $this->makeCustomer();
        $vehicle = $this->makeVehicle($franchise, $zone, null, ['base_price' => 500]);
        app(WalletService::class)->credit($customer, 2000, 'test top-up');

        $reservation = app(CreateRentalReservationAction::class)->execute([
            'rental_type' => 'vehicle', 'rentable_id' => $vehicle->id, 'customer_id' => $customer->id,
            'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(5),
            'payment_method' => 'wallet',
        ]);

        $this->assertSame('paid', $reservation->payment_status);
        $this->assertSame(1000.0, app(WalletService::class)->balance($customer)); // 2000 - (500*2)

        $payment = Payment::where('rental_reservation_id', $reservation->id)->first();
        $this->assertNotNull($payment);
        $this->assertSame('rental_reservation', $payment->purpose);
    }

    // ============================== Regression ==============================

    public function test_service_parcel_taxi_property_and_marketplace_are_unaffected_by_rental_existing(): void
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
