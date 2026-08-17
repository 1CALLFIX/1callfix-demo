<?php

namespace Tests\Feature\Hotel;

use App\Actions\AdminCancelHotelReservationAction;
use App\Actions\CheckInHotelReservationAction;
use App\Actions\CheckOutHotelReservationAction;
use App\Actions\CompleteHotelReservationAction;
use App\Actions\ConfirmHotelReservationAction;
use App\Actions\CreateHotelReservationAction;
use App\Exceptions\ModuleNotActiveException;
use App\Models\Commission;
use App\Models\HotelGuest;
use App\Models\HotelReservationRoom;
use App\Models\HotelRoomAvailability;
use App\Models\Payment;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\HotelFixtureHelpers;
use Tests\TestCase;

/**
 * HOTEL / STAY BOOKING MODULE. Core lifecycle coverage: module gate,
 * creation (single + multi-room), date-range validation, occupancy-capacity
 * validation, per-date price override, quantity-based overbooking
 * prevention, the full pending->confirmed->checked_in->checked_out->completed
 * flow, commission, cancellation + inventory release, wallet payment,
 * guests, and a cross-vertical regression check.
 */
class HotelReservationLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;
    use HotelFixtureHelpers;

    // ============================== Creation ==============================

    public function test_creating_a_reservation_is_blocked_while_the_module_is_not_implemented(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        $accommodation = $this->makeAccommodation($franchise, $zone);
        $roomType = $this->makeRoomType($accommodation);
        $ratePlan = $this->makeRatePlan($roomType);

        $this->expectException(ModuleNotActiveException::class);

        app(CreateHotelReservationAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
            'accommodation_id' => $accommodation->id,
            'check_in_date' => now()->addDays(3)->toDateString(), 'check_out_date' => now()->addDays(5)->toDateString(),
            'rooms' => [['room_type_id' => $roomType->id, 'rate_plan_id' => $ratePlan->id, 'room_count' => 1]],
        ]);
    }

    public function test_creating_a_single_room_reservation_succeeds_once_the_module_is_enabled(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateHotelFor($franchise);
        $customer = $this->makeCustomer();
        $accommodation = $this->makeAccommodation($franchise, $zone);
        $roomType = $this->makeRoomType($accommodation);
        $ratePlan = $this->makeRatePlan($roomType, ['nightly_rate' => 1000]);

        $reservation = app(CreateHotelReservationAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
            'accommodation_id' => $accommodation->id,
            'check_in_date' => now()->addDays(3)->toDateString(), 'check_out_date' => now()->addDays(5)->toDateString(),
            'number_of_adults' => 2,
            'rooms' => [['room_type_id' => $roomType->id, 'rate_plan_id' => $ratePlan->id, 'room_count' => 1]],
        ]);

        $this->assertNotNull($reservation->id);
        $this->assertSame('pending', $reservation->status);
        $this->assertStringContainsString('-HTL-', $reservation->code);
        $this->assertSame(2, $reservation->number_of_nights);
        $this->assertSame(1, $reservation->number_of_rooms);
        $this->assertSame(2000.0, (float) $reservation->price_quoted); // 2 nights * 1000
        $this->assertSame(1, HotelReservationRoom::where('hotel_reservation_id', $reservation->id)->count());
    }

    public function test_creating_a_multi_room_reservation_sums_every_line(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateHotelFor($franchise);
        $customer = $this->makeCustomer();
        $accommodation = $this->makeAccommodation($franchise, $zone);
        $deluxe = $this->makeRoomType($accommodation, ['name' => 'Deluxe', 'total_inventory' => 5, 'max_adults' => 2]);
        $deluxeRate = $this->makeRatePlan($deluxe, ['nightly_rate' => 1000]);
        $suite = $this->makeRoomType($accommodation, ['name' => 'Suite', 'total_inventory' => 3, 'max_adults' => 4]);
        $suiteRate = $this->makeRatePlan($suite, ['nightly_rate' => 2500]);

        $reservation = app(CreateHotelReservationAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
            'accommodation_id' => $accommodation->id,
            'check_in_date' => now()->addDays(3)->toDateString(), 'check_out_date' => now()->addDays(5)->toDateString(),
            'number_of_adults' => 6,
            'rooms' => [
                ['room_type_id' => $deluxe->id, 'rate_plan_id' => $deluxeRate->id, 'room_count' => 2],
                ['room_type_id' => $suite->id, 'rate_plan_id' => $suiteRate->id, 'room_count' => 1],
            ],
        ]);

        // 2 nights * (2*1000 + 1*2500) = 2 * 4500 = 9000
        $this->assertSame(9000.0, (float) $reservation->price_quoted);
        $this->assertSame(3, $reservation->number_of_rooms);
        $this->assertSame(2, HotelReservationRoom::where('hotel_reservation_id', $reservation->id)->count());
    }

    public function test_at_least_one_room_line_is_required(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateHotelFor($franchise);
        $customer = $this->makeCustomer();
        $accommodation = $this->makeAccommodation($franchise, $zone);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('At least one room');

        app(CreateHotelReservationAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
            'accommodation_id' => $accommodation->id,
            'check_in_date' => now()->addDays(3)->toDateString(), 'check_out_date' => now()->addDays(5)->toDateString(),
            'rooms' => [],
        ]);
    }

    public function test_check_out_must_be_after_check_in(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateHotelFor($franchise);
        $customer = $this->makeCustomer();
        $accommodation = $this->makeAccommodation($franchise, $zone);
        $roomType = $this->makeRoomType($accommodation);
        $ratePlan = $this->makeRatePlan($roomType);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Check-out date must be after check-in date');

        app(CreateHotelReservationAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
            'accommodation_id' => $accommodation->id,
            'check_in_date' => now()->addDays(5)->toDateString(), 'check_out_date' => now()->addDays(5)->toDateString(),
            'rooms' => [['room_type_id' => $roomType->id, 'rate_plan_id' => $ratePlan->id, 'room_count' => 1]],
        ]);
    }

    public function test_occupancy_capacity_is_enforced_against_selected_rooms(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateHotelFor($franchise);
        $customer = $this->makeCustomer();
        $accommodation = $this->makeAccommodation($franchise, $zone);
        $roomType = $this->makeRoomType($accommodation, ['max_adults' => 2]);
        $ratePlan = $this->makeRatePlan($roomType);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('can accommodate at most 2 adult');

        app(CreateHotelReservationAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
            'accommodation_id' => $accommodation->id,
            'check_in_date' => now()->addDays(3)->toDateString(), 'check_out_date' => now()->addDays(5)->toDateString(),
            'number_of_adults' => 5,
            'rooms' => [['room_type_id' => $roomType->id, 'rate_plan_id' => $ratePlan->id, 'room_count' => 1]],
        ]);
    }

    public function test_per_date_price_override_is_applied(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateHotelFor($franchise);
        $customer = $this->makeCustomer();
        $accommodation = $this->makeAccommodation($franchise, $zone);
        $roomType = $this->makeRoomType($accommodation);
        $ratePlan = $this->makeRatePlan($roomType, ['nightly_rate' => 1000]);

        $overrideDate = now()->addDays(3)->toDateString();
        HotelRoomAvailability::create(['hotel_room_type_id' => $roomType->id, 'date' => $overrideDate, 'is_available' => true, 'price_override' => 1500]);

        $reservation = app(CreateHotelReservationAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
            'accommodation_id' => $accommodation->id,
            'check_in_date' => $overrideDate, 'check_out_date' => now()->addDays(5)->toDateString(),
            'rooms' => [['room_type_id' => $roomType->id, 'rate_plan_id' => $ratePlan->id, 'room_count' => 1]],
        ]);

        // night 1 (override) = 1500, night 2 = 1000 -> 2500
        $this->assertSame(2500.0, (float) $reservation->price_quoted);
    }

    public function test_cannot_reserve_more_rooms_than_total_inventory(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateHotelFor($franchise);
        $customer = $this->makeCustomer();
        $accommodation = $this->makeAccommodation($franchise, $zone);
        $roomType = $this->makeRoomType($accommodation, ['total_inventory' => 2, 'max_adults' => 10]);
        $ratePlan = $this->makeRatePlan($roomType);

        app(CreateHotelReservationAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
            'accommodation_id' => $accommodation->id,
            'check_in_date' => now()->addDays(3)->toDateString(), 'check_out_date' => now()->addDays(5)->toDateString(),
            'rooms' => [['room_type_id' => $roomType->id, 'rate_plan_id' => $ratePlan->id, 'room_count' => 2]],
        ]);

        $secondCustomer = $this->makeCustomer();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not have');

        // Only 2 total, already fully booked -- requesting 1 more must fail.
        app(CreateHotelReservationAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $secondCustomer->id,
            'accommodation_id' => $accommodation->id,
            'check_in_date' => now()->addDays(4)->toDateString(), 'check_out_date' => now()->addDays(6)->toDateString(),
            'rooms' => [['room_type_id' => $roomType->id, 'rate_plan_id' => $ratePlan->id, 'room_count' => 1]],
        ]);
    }

    public function test_a_conflicting_multi_room_reservation_does_not_leave_a_partial_reservation_row(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateHotelFor($franchise);
        $customer = $this->makeCustomer();
        $accommodation = $this->makeAccommodation($franchise, $zone);
        $deluxe = $this->makeRoomType($accommodation, ['total_inventory' => 5]);
        $deluxeRate = $this->makeRatePlan($deluxe);
        $suite = $this->makeRoomType($accommodation, ['total_inventory' => 1]);
        $suiteRate = $this->makeRatePlan($suite);

        // Fully consume the suite inventory first.
        app(CreateHotelReservationAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
            'accommodation_id' => $accommodation->id,
            'check_in_date' => now()->addDays(3)->toDateString(), 'check_out_date' => now()->addDays(5)->toDateString(),
            'rooms' => [['room_type_id' => $suite->id, 'rate_plan_id' => $suiteRate->id, 'room_count' => 1]],
        ]);

        $secondCustomer = $this->makeCustomer();

        try {
            // deluxe line would succeed, but the suite line (second) must
            // fail and roll back the deluxe reservation already made in the
            // same transaction too.
            app(CreateHotelReservationAction::class)->execute([
                'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $secondCustomer->id,
                'accommodation_id' => $accommodation->id,
                'check_in_date' => now()->addDays(3)->toDateString(), 'check_out_date' => now()->addDays(5)->toDateString(),
                'rooms' => [
                    ['room_type_id' => $deluxe->id, 'rate_plan_id' => $deluxeRate->id, 'room_count' => 1],
                    ['room_type_id' => $suite->id, 'rate_plan_id' => $suiteRate->id, 'room_count' => 1],
                ],
            ]);
            $this->fail('Expected a RuntimeException for the exhausted suite inventory.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(1, \App\Models\HotelReservation::count(), 'The transaction must roll back the whole reservation (both lines) when any one line\'s inventory lock fails.');
        $this->assertSame(0, HotelRoomAvailability::where('hotel_room_type_id', $deluxe->id)->sum('rooms_booked'), 'The deluxe line reserved before the failing suite line must be rolled back too.');
    }

    public function test_named_guests_are_created_with_the_reservation(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateHotelFor($franchise);
        $customer = $this->makeCustomer();
        $accommodation = $this->makeAccommodation($franchise, $zone);
        $roomType = $this->makeRoomType($accommodation);
        $ratePlan = $this->makeRatePlan($roomType);

        $reservation = app(CreateHotelReservationAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
            'accommodation_id' => $accommodation->id,
            'check_in_date' => now()->addDays(3)->toDateString(), 'check_out_date' => now()->addDays(5)->toDateString(),
            'rooms' => [['room_type_id' => $roomType->id, 'rate_plan_id' => $ratePlan->id, 'room_count' => 1]],
            'guests' => [
                ['name' => 'Alice', 'guest_type' => 'adult', 'is_primary' => true],
                ['name' => 'Bobby', 'guest_type' => 'child'],
            ],
        ]);

        $this->assertSame(2, HotelGuest::where('hotel_reservation_id', $reservation->id)->count());
        $this->assertTrue(HotelGuest::where('hotel_reservation_id', $reservation->id)->where('name', 'Alice')->where('is_primary', true)->exists());
    }

    // ============================== Lifecycle ==============================

    public function test_full_lifecycle_confirm_checkin_checkout_complete_applies_commission(): void
    {
        $scenario = $this->makeHotelReservationScenario('pending');
        $scenario['reservation']->update(['price_quoted' => 2000]);
        $scenario['franchise']->update(['platform_fee_percent' => 20, 'commission_model' => 'revenue_share', 'commission_value' => 10, 'owner_user_id' => null]);

        $confirmed = app(ConfirmHotelReservationAction::class)->execute($scenario['reservation']->id);
        $this->assertSame('confirmed', $confirmed->status);

        $checkedIn = app(CheckInHotelReservationAction::class)->execute($scenario['reservation']->id);
        $this->assertSame('checked_in', $checkedIn->status);

        $checkedOut = app(CheckOutHotelReservationAction::class)->execute($scenario['reservation']->id);
        $this->assertSame('checked_out', $checkedOut->status);
        $this->assertNotNull($checkedOut->checked_out_at);

        $completed = app(CompleteHotelReservationAction::class)->execute($scenario['reservation']->id);
        $this->assertSame('completed', $completed->status);
        $this->assertSame(2000.0, (float) $completed->price_final);

        $commission = Commission::where('hotel_reservation_id', $completed->id)->first();
        $this->assertNotNull($commission);
        $this->assertSame(1400.0, (float) $commission->provider_commission); // 2000 - 400(platform) - 200(franchise)

        $this->assertSame(1400.0, app(WalletService::class)->balance($scenario['owner']->user));
    }

    public function test_cannot_check_in_before_confirmed(): void
    {
        $scenario = $this->makeHotelReservationScenario('pending');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot be checked in from status');

        app(CheckInHotelReservationAction::class)->execute($scenario['reservation']->id);
    }

    public function test_cannot_check_out_before_checked_in(): void
    {
        $scenario = $this->makeHotelReservationScenario('confirmed');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot be checked out from status');

        app(CheckOutHotelReservationAction::class)->execute($scenario['reservation']->id);
    }

    public function test_cannot_complete_before_checked_out(): void
    {
        $scenario = $this->makeHotelReservationScenario('checked_in');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot be completed from status');

        app(CompleteHotelReservationAction::class)->execute($scenario['reservation']->id);
    }

    public function test_completion_is_idempotent_and_never_double_credits(): void
    {
        $scenario = $this->makeHotelReservationScenario('checked_out');
        $scenario['reservation']->update(['price_quoted' => 1000]);
        $scenario['franchise']->update(['platform_fee_percent' => 0, 'commission_model' => 'flat_fee', 'commission_value' => 0]);

        app(CompleteHotelReservationAction::class)->execute($scenario['reservation']->id);
        app(\App\Services\CommissionService::class)->applyForHotelReservation($scenario['reservation']->fresh());

        $this->assertSame(1, Commission::where('hotel_reservation_id', $scenario['reservation']->id)->count());
        $this->assertSame(1000.0, app(WalletService::class)->balance($scenario['owner']->user));
    }

    // ============================== Cancellation ==============================

    public function test_admin_can_cancel_a_pending_reservation_and_inventory_is_released(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateHotelFor($franchise);
        $customer = $this->makeCustomer();
        $accommodation = $this->makeAccommodation($franchise, $zone);
        $roomType = $this->makeRoomType($accommodation, ['total_inventory' => 1]);
        $ratePlan = $this->makeRatePlan($roomType);

        $reservation = app(CreateHotelReservationAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
            'accommodation_id' => $accommodation->id,
            'check_in_date' => now()->addDays(3)->toDateString(), 'check_out_date' => now()->addDays(6)->toDateString(),
            'rooms' => [['room_type_id' => $roomType->id, 'rate_plan_id' => $ratePlan->id, 'room_count' => 1]],
        ]);

        app(AdminCancelHotelReservationAction::class)->execute($reservation->id, 'Customer requested');

        $this->assertSame('cancelled', $reservation->fresh()->status);

        // Inventory released -- a new reservation for the exact same range must now succeed.
        $secondCustomer = $this->makeCustomer();
        $second = app(CreateHotelReservationAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $secondCustomer->id,
            'accommodation_id' => $accommodation->id,
            'check_in_date' => now()->addDays(3)->toDateString(), 'check_out_date' => now()->addDays(6)->toDateString(),
            'rooms' => [['room_type_id' => $roomType->id, 'rate_plan_id' => $ratePlan->id, 'room_count' => 1]],
        ]);
        $this->assertNotNull($second->id);
    }

    public function test_cannot_cancel_an_already_completed_reservation(): void
    {
        $scenario = $this->makeHotelReservationScenario('completed');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already completed');

        app(AdminCancelHotelReservationAction::class)->execute($scenario['reservation']->id, 'too late');
    }

    public function test_cannot_cancel_an_already_checked_out_reservation(): void
    {
        $scenario = $this->makeHotelReservationScenario('checked_out');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already checked_out');

        app(AdminCancelHotelReservationAction::class)->execute($scenario['reservation']->id, 'too late');
    }

    public function test_cancellation_fee_is_zero_within_the_free_window_before_checkin(): void
    {
        \App\Models\Setting::set('hotel.free_cancellation_hours_before_checkin', '48');
        \App\Models\Setting::set('hotel.cancellation_fee_type', 'flat');
        \App\Models\Setting::set('hotel.cancellation_fee_value', '200');

        $scenario = $this->makeHotelReservationScenario('pending'); // check-in is 5 days out in the fixture

        $reservation = app(AdminCancelHotelReservationAction::class)->execute($scenario['reservation']->id, 'test');

        $this->assertSame(0.0, (float) $reservation->cancellation_fee);
    }

    public function test_cancellation_fee_applies_when_cancelled_close_to_checkin(): void
    {
        \App\Models\Setting::set('hotel.free_cancellation_hours_before_checkin', '48');
        \App\Models\Setting::set('hotel.cancellation_fee_type', 'flat');
        \App\Models\Setting::set('hotel.cancellation_fee_value', '200');

        $scenario = $this->makeHotelReservationScenario('pending');
        $scenario['reservation']->update(['check_in_date' => now()->addHours(10)->toDateString()]);

        $reservation = app(AdminCancelHotelReservationAction::class)->execute($scenario['reservation']->id, 'test');

        $this->assertSame(200.0, (float) $reservation->cancellation_fee);
    }

    // ============================== Payment ==============================

    public function test_wallet_payment_debits_customer_and_records_a_captured_payment(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateHotelFor($franchise);
        $customer = $this->makeCustomer();
        $accommodation = $this->makeAccommodation($franchise, $zone);
        $roomType = $this->makeRoomType($accommodation);
        $ratePlan = $this->makeRatePlan($roomType, ['nightly_rate' => 500]);
        app(WalletService::class)->credit($customer, 2000, 'test top-up');

        $reservation = app(CreateHotelReservationAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
            'accommodation_id' => $accommodation->id,
            'check_in_date' => now()->addDays(3)->toDateString(), 'check_out_date' => now()->addDays(5)->toDateString(),
            'rooms' => [['room_type_id' => $roomType->id, 'rate_plan_id' => $ratePlan->id, 'room_count' => 1]],
            'payment_method' => 'wallet',
        ]);

        $this->assertSame('paid', $reservation->payment_status);
        $this->assertSame(1000.0, app(WalletService::class)->balance($customer)); // 2000 - (500*2)

        $payment = Payment::where('hotel_reservation_id', $reservation->id)->first();
        $this->assertNotNull($payment);
        $this->assertSame('hotel_reservation', $payment->purpose);
    }

    // ============================== Module independence ==============================

    /** Mission requirement: Hotel must be independently activatable -- never dependent on `rental` being enabled. */
    public function test_hotel_reservation_is_not_blocked_by_rental_being_disabled(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateHotelFor($franchise); // only hotel, never rental
        $customer = $this->makeCustomer();
        $accommodation = $this->makeAccommodation($franchise, $zone);
        $roomType = $this->makeRoomType($accommodation);
        $ratePlan = $this->makeRatePlan($roomType);

        $this->assertFalse(app(\App\Services\ModuleActivationService::class)->isActive(\App\Support\Modules::RENTAL, ['franchise_id' => $franchise->id]));

        $reservation = app(CreateHotelReservationAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
            'accommodation_id' => $accommodation->id,
            'check_in_date' => now()->addDays(3)->toDateString(), 'check_out_date' => now()->addDays(5)->toDateString(),
            'rooms' => [['room_type_id' => $roomType->id, 'rate_plan_id' => $ratePlan->id, 'room_count' => 1]],
        ]);

        $this->assertNotNull($reservation->id);
    }

    /** Mirror requirement: enabling `rental` must never implicitly enable `hotel`. */
    public function test_enabling_rental_does_not_implicitly_enable_hotel(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        \App\Models\Module::where('code', \App\Support\Modules::RENTAL)->update(['is_implemented' => true]);
        app(\App\Services\ModuleActivationService::class)->setActive(\App\Support\Modules::RENTAL, 'franchise', $franchise->id, true);

        $customer = $this->makeCustomer();
        $accommodation = $this->makeAccommodation($franchise, $zone);
        $roomType = $this->makeRoomType($accommodation);
        $ratePlan = $this->makeRatePlan($roomType);

        $this->expectException(ModuleNotActiveException::class);

        app(CreateHotelReservationAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
            'accommodation_id' => $accommodation->id,
            'check_in_date' => now()->addDays(3)->toDateString(), 'check_out_date' => now()->addDays(5)->toDateString(),
            'rooms' => [['room_type_id' => $roomType->id, 'rate_plan_id' => $ratePlan->id, 'room_count' => 1]],
        ]);
    }

    // ============================== Regression ==============================

    public function test_service_and_property_rental_are_completely_unaffected_by_hotel_existing(): void
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
