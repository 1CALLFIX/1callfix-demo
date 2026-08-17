<?php

namespace Tests\Feature\PropertyRental;

use App\Actions\AdminCancelPropertyReservationAction;
use App\Actions\CheckInPropertyReservationAction;
use App\Actions\CompletePropertyReservationAction;
use App\Actions\ConfirmPropertyReservationAction;
use App\Actions\CreatePropertyReservationAction;
use App\Exceptions\ModuleNotActiveException;
use App\Models\Commission;
use App\Models\Payment;
use App\Models\PropertyAvailability;
use App\Services\PropertyAvailabilityService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\PropertyRentalFixtureHelpers;
use Tests\TestCase;

/**
 * Phase 22.7 (Property Rental). Core lifecycle coverage: module gate,
 * creation, date-range validation, min/max nights, pricing (including
 * per-date overrides), availability conflict detection, the full
 * pending->confirmed->checked_in->completed flow, commission, cancellation
 * + date release, wallet payment, and a cross-vertical regression check.
 */
class PropertyReservationLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;
    use PropertyRentalFixtureHelpers;

    public function test_creating_a_reservation_is_blocked_while_the_module_is_not_implemented(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        $property = $this->makeProperty($franchise, $zone);

        $this->expectException(ModuleNotActiveException::class);

        app(CreatePropertyReservationAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
            'property_id' => $property->id, 'check_in_date' => now()->addDays(3)->toDateString(), 'check_out_date' => now()->addDays(5)->toDateString(),
        ]);
    }

    public function test_creating_a_reservation_succeeds_once_the_module_is_enabled(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activatePropertyRentalFor($franchise);
        $customer = $this->makeCustomer();
        $property = $this->makeProperty($franchise, $zone, null, ['base_price' => 1000]);

        $reservation = app(CreatePropertyReservationAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
            'property_id' => $property->id, 'check_in_date' => now()->addDays(3)->toDateString(), 'check_out_date' => now()->addDays(5)->toDateString(),
        ]);

        $this->assertNotNull($reservation->id);
        $this->assertSame('pending', $reservation->status);
        $this->assertStringContainsString('-PRP-', $reservation->code);
        $this->assertSame(2, $reservation->number_of_nights);
        $this->assertSame(2000.0, (float) $reservation->price_quoted); // 2 nights * 1000
    }

    public function test_check_out_must_be_after_check_in(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activatePropertyRentalFor($franchise);
        $customer = $this->makeCustomer();
        $property = $this->makeProperty($franchise, $zone);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Check-out date must be after check-in date');

        app(CreatePropertyReservationAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
            'property_id' => $property->id, 'check_in_date' => now()->addDays(5)->toDateString(), 'check_out_date' => now()->addDays(5)->toDateString(),
        ]);
    }

    public function test_minimum_nights_is_enforced(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activatePropertyRentalFor($franchise);
        $customer = $this->makeCustomer();
        $property = $this->makeProperty($franchise, $zone, null, ['minimum_nights' => 3]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('minimum stay of 3 night');

        app(CreatePropertyReservationAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
            'property_id' => $property->id, 'check_in_date' => now()->addDays(3)->toDateString(), 'check_out_date' => now()->addDays(4)->toDateString(),
        ]);
    }

    public function test_maximum_nights_is_enforced(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activatePropertyRentalFor($franchise);
        $customer = $this->makeCustomer();
        $property = $this->makeProperty($franchise, $zone, null, ['maximum_nights' => 2]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('maximum stay of 2 night');

        app(CreatePropertyReservationAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
            'property_id' => $property->id, 'check_in_date' => now()->addDays(3)->toDateString(), 'check_out_date' => now()->addDays(8)->toDateString(),
        ]);
    }

    public function test_per_date_price_override_is_applied(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activatePropertyRentalFor($franchise);
        $customer = $this->makeCustomer();
        $property = $this->makeProperty($franchise, $zone, null, ['base_price' => 1000]);

        $overrideDate = now()->addDays(3)->toDateString();
        PropertyAvailability::create(['property_id' => $property->id, 'date' => $overrideDate, 'is_available' => true, 'price_override' => 1500]);

        $reservation = app(CreatePropertyReservationAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
            'property_id' => $property->id, 'check_in_date' => $overrideDate, 'check_out_date' => now()->addDays(5)->toDateString(),
        ]);

        // night 1 (override) = 1500, night 2 = 1000 -> 2500
        $this->assertSame(2500.0, (float) $reservation->price_quoted);
    }

    public function test_cannot_reserve_dates_that_are_already_booked(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activatePropertyRentalFor($franchise);
        $customer = $this->makeCustomer();
        $property = $this->makeProperty($franchise, $zone);

        app(CreatePropertyReservationAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
            'property_id' => $property->id, 'check_in_date' => now()->addDays(3)->toDateString(), 'check_out_date' => now()->addDays(6)->toDateString(),
        ]);

        $secondCustomer = $this->makeCustomer();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not available');

        // Overlapping range.
        app(CreatePropertyReservationAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $secondCustomer->id,
            'property_id' => $property->id, 'check_in_date' => now()->addDays(4)->toDateString(), 'check_out_date' => now()->addDays(7)->toDateString(),
        ]);
    }

    public function test_a_conflicting_reservation_does_not_leave_a_partial_reservation_row(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activatePropertyRentalFor($franchise);
        $customer = $this->makeCustomer();
        $property = $this->makeProperty($franchise, $zone);

        app(CreatePropertyReservationAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
            'property_id' => $property->id, 'check_in_date' => now()->addDays(3)->toDateString(), 'check_out_date' => now()->addDays(6)->toDateString(),
        ]);

        try {
            app(CreatePropertyReservationAction::class)->execute([
                'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
                'property_id' => $property->id, 'check_in_date' => now()->addDays(4)->toDateString(), 'check_out_date' => now()->addDays(7)->toDateString(),
            ]);
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(1, \App\Models\PropertyReservation::count(), 'The transaction must roll back the reservation row when the date lock fails -- no orphaned "pending" reservation for dates it never actually holds.');
    }

    public function test_adjacent_non_overlapping_reservations_are_both_allowed(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activatePropertyRentalFor($franchise);
        $customerA = $this->makeCustomer();
        $customerB = $this->makeCustomer();
        $property = $this->makeProperty($franchise, $zone);

        // [day3, day5) then [day5, day7) -- share the boundary date but don't overlap (half-open range).
        $first = app(CreatePropertyReservationAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customerA->id,
            'property_id' => $property->id, 'check_in_date' => now()->addDays(3)->toDateString(), 'check_out_date' => now()->addDays(5)->toDateString(),
        ]);
        $second = app(CreatePropertyReservationAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customerB->id,
            'property_id' => $property->id, 'check_in_date' => now()->addDays(5)->toDateString(), 'check_out_date' => now()->addDays(7)->toDateString(),
        ]);

        $this->assertNotNull($first->id);
        $this->assertNotNull($second->id);
    }

    // ============================== Lifecycle ==============================

    public function test_full_lifecycle_confirm_checkin_complete_applies_commission(): void
    {
        $scenario = $this->makePropertyReservationScenario('pending');
        $scenario['reservation']->update(['price_quoted' => 2000]);
        $scenario['franchise']->update(['platform_fee_percent' => 20, 'commission_model' => 'revenue_share', 'commission_value' => 10, 'owner_user_id' => null]);

        $confirmed = app(ConfirmPropertyReservationAction::class)->execute($scenario['reservation']->id);
        $this->assertSame('confirmed', $confirmed->status);
        $this->assertNotNull($confirmed->confirmed_at);

        $checkedIn = app(CheckInPropertyReservationAction::class)->execute($scenario['reservation']->id);
        $this->assertSame('checked_in', $checkedIn->status);

        $completed = app(CompletePropertyReservationAction::class)->execute($scenario['reservation']->id);
        $this->assertSame('completed', $completed->status);
        $this->assertSame(2000.0, (float) $completed->price_final);

        $commission = Commission::where('property_reservation_id', $completed->id)->first();
        $this->assertNotNull($commission);
        $this->assertSame(1400.0, (float) $commission->provider_commission); // 2000 - 400(platform) - 200(franchise)

        $this->assertSame(1400.0, app(WalletService::class)->balance($scenario['owner']->user));
    }

    public function test_cannot_check_in_before_confirmed(): void
    {
        $scenario = $this->makePropertyReservationScenario('pending');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot be checked in from status');

        app(CheckInPropertyReservationAction::class)->execute($scenario['reservation']->id);
    }

    public function test_cannot_complete_before_checked_in(): void
    {
        $scenario = $this->makePropertyReservationScenario('confirmed');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot be completed from status');

        app(CompletePropertyReservationAction::class)->execute($scenario['reservation']->id);
    }

    public function test_completion_is_idempotent_and_never_double_credits(): void
    {
        $scenario = $this->makePropertyReservationScenario('checked_in');
        $scenario['reservation']->update(['price_quoted' => 1000]);
        $scenario['franchise']->update(['platform_fee_percent' => 0, 'commission_model' => 'flat_fee', 'commission_value' => 0]);

        app(CompletePropertyReservationAction::class)->execute($scenario['reservation']->id);
        app(\App\Services\CommissionService::class)->applyForPropertyReservation($scenario['reservation']->fresh());

        $this->assertSame(1, Commission::where('property_reservation_id', $scenario['reservation']->id)->count());
        $this->assertSame(1000.0, app(WalletService::class)->balance($scenario['owner']->user));
    }

    // ============================== Cancellation ==============================

    public function test_admin_can_cancel_a_pending_reservation_and_dates_are_released(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activatePropertyRentalFor($franchise);
        $customer = $this->makeCustomer();
        $property = $this->makeProperty($franchise, $zone);

        $reservation = app(CreatePropertyReservationAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
            'property_id' => $property->id, 'check_in_date' => now()->addDays(3)->toDateString(), 'check_out_date' => now()->addDays(6)->toDateString(),
        ]);

        app(AdminCancelPropertyReservationAction::class)->execute($reservation->id, 'Customer requested');

        $this->assertSame('cancelled', $reservation->fresh()->status);

        // Dates released -- a new reservation for the exact same range must now succeed.
        $secondCustomer = $this->makeCustomer();
        $second = app(CreatePropertyReservationAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $secondCustomer->id,
            'property_id' => $property->id, 'check_in_date' => now()->addDays(3)->toDateString(), 'check_out_date' => now()->addDays(6)->toDateString(),
        ]);
        $this->assertNotNull($second->id);
    }

    public function test_cannot_cancel_an_already_completed_reservation(): void
    {
        $scenario = $this->makePropertyReservationScenario('completed');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already completed');

        app(AdminCancelPropertyReservationAction::class)->execute($scenario['reservation']->id, 'too late');
    }

    public function test_cancellation_fee_is_zero_within_the_free_window_before_checkin(): void
    {
        \App\Models\Setting::set('rental.free_cancellation_hours_before_checkin', '48');
        \App\Models\Setting::set('rental.cancellation_fee_type', 'flat');
        \App\Models\Setting::set('rental.cancellation_fee_value', '200');

        $scenario = $this->makePropertyReservationScenario('pending'); // check-in is 5 days out in the fixture

        $reservation = app(AdminCancelPropertyReservationAction::class)->execute($scenario['reservation']->id, 'test');

        $this->assertSame(0.0, (float) $reservation->cancellation_fee);
    }

    public function test_cancellation_fee_applies_when_cancelled_close_to_checkin(): void
    {
        \App\Models\Setting::set('rental.free_cancellation_hours_before_checkin', '48');
        \App\Models\Setting::set('rental.cancellation_fee_type', 'flat');
        \App\Models\Setting::set('rental.cancellation_fee_value', '200');

        $scenario = $this->makePropertyReservationScenario('pending');
        $scenario['reservation']->update(['check_in_date' => now()->addHours(10)->toDateString()]);

        $reservation = app(AdminCancelPropertyReservationAction::class)->execute($scenario['reservation']->id, 'test');

        $this->assertSame(200.0, (float) $reservation->cancellation_fee);
    }

    // ============================== Payment ==============================

    public function test_wallet_payment_debits_customer_and_records_a_captured_payment(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activatePropertyRentalFor($franchise);
        $customer = $this->makeCustomer();
        $property = $this->makeProperty($franchise, $zone, null, ['base_price' => 500]);
        app(WalletService::class)->credit($customer, 2000, 'test top-up');

        $reservation = app(CreatePropertyReservationAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
            'property_id' => $property->id, 'check_in_date' => now()->addDays(3)->toDateString(), 'check_out_date' => now()->addDays(5)->toDateString(),
            'payment_method' => 'wallet',
        ]);

        $this->assertSame('paid', $reservation->payment_status);
        $this->assertSame(1000.0, app(WalletService::class)->balance($customer)); // 2000 - (500*2)

        $payment = Payment::where('property_reservation_id', $reservation->id)->first();
        $this->assertNotNull($payment);
        $this->assertSame('property_reservation', $payment->purpose);
    }

    // ============================== Regression ==============================

    public function test_service_parcel_and_taxi_are_completely_unaffected_by_property_rental_existing(): void
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
