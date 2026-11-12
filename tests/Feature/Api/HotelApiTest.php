<?php

namespace Tests\Feature\Api;

use App\Models\HotelReservation;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\HotelFixtureHelpers;
use Tests\TestCase;

/**
 * HOTEL / STAY BOOKING MODULE. Real HTTP-level coverage: public browse (no
 * auth required), room-type/rate-plan listing, availability, authenticated
 * multi-room reservation creation, IDOR-safe "my reservations"/detail,
 * module-gate enforcement at the API layer.
 */
class HotelApiTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;
    use HotelFixtureHelpers;

    public function test_browsing_hotels_requires_no_authentication(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->makeAccommodation($franchise, $zone, null, ['approval_status' => 'approved']);

        $this->getJson('/api/hotels')
            ->assertOk()
            ->assertJsonCount(1, 'accommodations');
    }

    public function test_hotel_detail_is_public(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $accommodation = $this->makeAccommodation($franchise, $zone, null, ['approval_status' => 'approved']);

        $this->getJson("/api/hotels/{$accommodation->id}")
            ->assertOk()
            ->assertJsonPath('accommodation.id', $accommodation->id);
    }

    public function test_inactive_hotels_do_not_appear_in_browse_or_detail(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $accommodation = $this->makeAccommodation($franchise, $zone, null, ['is_active' => false, 'approval_status' => 'approved']);

        $this->getJson('/api/hotels')->assertJsonCount(0, 'accommodations');
        $this->getJson("/api/hotels/{$accommodation->id}")->assertStatus(404);
    }

    public function test_room_types_endpoint_includes_active_rate_plans(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $accommodation = $this->makeAccommodation($franchise, $zone, null, ['approval_status' => 'approved']);
        $roomType = $this->makeRoomType($accommodation);
        $this->makeRatePlan($roomType, ['name' => 'Standard']);
        $this->makeRatePlan($roomType, ['name' => 'Inactive', 'is_active' => false]);

        $response = $this->getJson("/api/hotels/{$accommodation->id}/room-types")->assertOk();

        $ratePlans = collect($response->json('room_types.0.rate_plans'))->pluck('name');
        $this->assertTrue($ratePlans->contains('Standard'));
        $this->assertFalse($ratePlans->contains('Inactive'));
    }

    public function test_availability_endpoint_reflects_a_real_booked_range(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateHotelFor($franchise);
        $accommodation = $this->makeAccommodation($franchise, $zone, null, ['approval_status' => 'approved']);
        $roomType = $this->makeRoomType($accommodation, ['total_inventory' => 1]);
        $ratePlan = $this->makeRatePlan($roomType);
        $customer = $this->makeCustomer();

        app(\App\Actions\CreateHotelReservationAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
            'accommodation_id' => $accommodation->id,
            'check_in_date' => now()->addDays(3)->toDateString(), 'check_out_date' => now()->addDays(6)->toDateString(),
            'rooms' => [['room_type_id' => $roomType->id, 'rate_plan_id' => $ratePlan->id, 'room_count' => 1]],
        ]);

        $this->getJson("/api/hotels/{$accommodation->id}/room-types/{$roomType->id}/availability?check_in=".now()->addDays(4)->toDateString().'&check_out='.now()->addDays(5)->toDateString())
            ->assertOk()
            ->assertJsonPath('is_available', false);

        $this->getJson("/api/hotels/{$accommodation->id}/room-types/{$roomType->id}/availability?check_in=".now()->addDays(20)->toDateString().'&check_out='.now()->addDays(22)->toDateString())
            ->assertOk()
            ->assertJsonPath('is_available', true);
    }

    public function test_reservation_creation_requires_authentication(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $accommodation = $this->makeAccommodation($franchise, $zone, null, ['approval_status' => 'approved']);
        $roomType = $this->makeRoomType($accommodation);
        $ratePlan = $this->makeRatePlan($roomType);

        $this->postJson('/api/hotel-reservations', [
            'accommodation_id' => $accommodation->id,
            'check_in_date' => now()->addDays(3)->toDateString(), 'check_out_date' => now()->addDays(5)->toDateString(),
            'rooms' => [['room_type_id' => $roomType->id, 'rate_plan_id' => $ratePlan->id, 'room_count' => 1]],
        ])->assertStatus(401);
    }

    public function test_reservation_creation_is_blocked_while_the_module_is_disabled(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $accommodation = $this->makeAccommodation($franchise, $zone, null, ['approval_status' => 'approved']);
        $roomType = $this->makeRoomType($accommodation);
        $ratePlan = $this->makeRatePlan($roomType);
        $customer = $this->makeCustomer();

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/hotel-reservations', [
                'accommodation_id' => $accommodation->id,
                'check_in_date' => now()->addDays(3)->toDateString(), 'check_out_date' => now()->addDays(5)->toDateString(),
                'rooms' => [['room_type_id' => $roomType->id, 'rate_plan_id' => $ratePlan->id, 'room_count' => 1]],
            ])
            ->assertStatus(422);
    }

    public function test_reservation_creation_succeeds_once_enabled(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateHotelFor($franchise);
        Setting::set('hotel.gst_slabs', "1000 : 0\n7500 : 5\n* : 18");
        $accommodation = $this->makeAccommodation($franchise, $zone, null, ['approval_status' => 'approved']);
        $roomType = $this->makeRoomType($accommodation);
        $ratePlan = $this->makeRatePlan($roomType);
        $customer = $this->makeCustomer();

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/hotel-reservations', [
                'accommodation_id' => $accommodation->id,
                'check_in_date' => now()->addDays(3)->toDateString(), 'check_out_date' => now()->addDays(5)->toDateString(),
                'number_of_adults' => 2,
                'rooms' => [['room_type_id' => $roomType->id, 'rate_plan_id' => $ratePlan->id, 'room_count' => 1]],
                'guests' => [['name' => 'Alice', 'guest_type' => 'adult', 'is_primary' => true]],
            ])
            ->assertStatus(201)
            ->assertJsonPath('reservation.status', 'pending')
            ->assertJsonPath('reservation.payment_method', 'online');
    }

    public function test_a_customer_cannot_view_another_customers_reservation_direct_id_manipulation(): void
    {
        $scenario = $this->makeHotelReservationScenario('pending');
        $otherCustomer = $this->makeCustomer();

        $this->actingAs($otherCustomer, 'sanctum')
            ->getJson("/api/hotel-reservations/{$scenario['reservation']->id}")
            ->assertStatus(404);
    }

    public function test_mine_only_returns_the_callers_own_reservations(): void
    {
        $mine = $this->makeHotelReservationScenario('pending');
        $other = $this->makeHotelReservationScenario('pending');

        $this->actingAs($mine['customer'], 'sanctum')
            ->getJson('/api/hotel-reservations/mine')
            ->assertOk()
            ->assertJsonCount(1, 'reservations')
            ->assertJsonPath('reservations.0.id', $mine['reservation']->id);
    }

    /** Owner 2026-11-12 ("anything missing vs the aggregators"): the app books exactly like the website. */
    private function bookableHotel(array $overrides = []): array
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateHotelFor($franchise);
        Setting::set('hotel.gst_slabs', "1000 : 0\n7500 : 5\n* : 18");
        $hotel = $this->makeAccommodation($franchise, $zone, null, $overrides + ['approval_status' => 'approved']);
        $room = $this->makeRoomType($hotel, ['total_inventory' => 2, 'max_adults' => 2]);
        $plan = $this->makeRatePlan($room, ['nightly_rate' => 2000]);

        return [$hotel, $room, $plan, $this->makeCustomer()];
    }

    private function body($hotel, $room, $plan, array $overrides = []): array
    {
        return $overrides + [
            'accommodation_id' => $hotel->id,
            'check_in_date' => now()->addDays(10)->toDateString(), 'check_out_date' => now()->addDays(12)->toDateString(),
            'number_of_adults' => 2,
            'rooms' => [['room_type_id' => $room->id, 'rate_plan_id' => $plan->id, 'room_count' => 1]],
            'guests' => [['name' => 'Asha', 'is_primary' => true]],
        ];
    }

    public function test_a_hotel_waiting_for_approval_is_not_listed_or_bookable_in_the_app(): void
    {
        [$hotel, $room, $plan, $customer] = $this->bookableHotel(['approval_status' => 'pending']);

        $this->getJson('/api/hotels')->assertJsonCount(0, 'accommodations');
        $this->getJson("/api/hotels/{$hotel->id}")->assertNotFound();
        $this->getJson("/api/hotels/{$hotel->id}/room-types")->assertNotFound();
        $this->actingAs($customer, 'sanctum')->postJson('/api/hotel-reservations', $this->body($hotel, $room, $plan))->assertNotFound();
        $this->assertSame(0, HotelReservation::count());
    }

    public function test_the_public_hotel_list_never_shows_the_owners_account(): void
    {
        [$hotel] = $this->bookableHotel();

        $json = $this->getJson('/api/hotels')->assertOk()->json('accommodations.0');
        $this->assertArrayNotHasKey('provider', $json);
        $this->assertArrayNotHasKey('provider', $this->getJson("/api/hotels/{$hotel->id}")->json('accommodation'));
    }

    public function test_cash_is_refused_in_the_app(): void
    {
        [$hotel, $room, $plan, $customer] = $this->bookableHotel();

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/hotel-reservations', $this->body($hotel, $room, $plan, ['payment_method' => 'cash']))
            ->assertStatus(422)->assertJsonValidationErrors('payment_method');
        $this->assertSame(0, HotelReservation::count());
    }

    public function test_the_app_charges_the_website_total_with_gst_and_holds_unpaid_rooms(): void
    {
        [$hotel, $room, $plan, $customer] = $this->bookableHotel();

        // 2 nights at Rs 2,000 in the 5% band: Rs 4,000 + Rs 200 GST.
        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/hotel-reservations', $this->body($hotel, $room, $plan))
            ->assertCreated()
            ->assertJsonPath('reservation.payment_status', 'pending');
        $r = HotelReservation::firstOrFail();
        $this->assertEquals(4000, (float) $r->price_quoted);
        $this->assertEquals(200, (float) $r->tax_amount);
        $this->assertEquals(4200, (float) $r->amount_due);
        $this->assertNotNull($r->payment_expires_at, 'An unpaid online booking holds the rooms only for a while.');
    }

    public function test_no_gst_bands_means_the_app_cannot_book_either(): void
    {
        [$hotel, $room, $plan, $customer] = $this->bookableHotel();
        Setting::set('hotel.gst_slabs', null);

        $this->actingAs($customer, 'sanctum')->postJson('/api/hotel-reservations', $this->body($hotel, $room, $plan))
            ->assertStatus(409)->assertJsonPath('message', 'Booking opens soon for this hotel.');
    }

    public function test_past_dates_too_far_ahead_and_too_long_stays_are_refused(): void
    {
        [$hotel, $room, $plan, $customer] = $this->bookableHotel();
        $this->actingAs($customer, 'sanctum');

        $this->postJson('/api/hotel-reservations', $this->body($hotel, $room, $plan, [
            'check_in_date' => now()->subDays(3)->toDateString(), 'check_out_date' => now()->subDay()->toDateString(),
        ]))->assertStatus(409);
        $this->postJson('/api/hotel-reservations', $this->body($hotel, $room, $plan, [
            'check_in_date' => now()->addDays(400)->toDateString(), 'check_out_date' => now()->addDays(401)->toDateString(),
        ]))->assertStatus(409);
        $this->postJson('/api/hotel-reservations', $this->body($hotel, $room, $plan, [
            'check_in_date' => now()->addDays(5)->toDateString(), 'check_out_date' => now()->addDays(60)->toDateString(),
        ]))->assertStatus(409);
        $this->assertSame(0, HotelReservation::count());
    }

    public function test_one_room_type_per_booking_in_the_app(): void
    {
        [$hotel, $room, $plan, $customer] = $this->bookableHotel();
        $line = ['room_type_id' => $room->id, 'rate_plan_id' => $plan->id, 'room_count' => 1];

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/hotel-reservations', $this->body($hotel, $room, $plan, ['rooms' => [$line, $line]]))
            ->assertStatus(422)->assertJsonValidationErrors('rooms');
    }

    public function test_only_the_owner_can_start_paying_and_only_while_unpaid(): void
    {
        [$hotel, $room, $plan, $customer] = $this->bookableHotel();
        $this->actingAs($customer, 'sanctum')->postJson('/api/hotel-reservations', $this->body($hotel, $room, $plan))->assertCreated();
        $r = HotelReservation::firstOrFail();

        $this->actingAs($this->makeCustomer(), 'sanctum')->postJson("/api/hotel-reservations/{$r->id}/pay")->assertNotFound();

        $r->forceFill(['payment_expires_at' => now()->subMinute()])->save();
        $this->actingAs($customer, 'sanctum')->postJson("/api/hotel-reservations/{$r->id}/pay")
            ->assertStatus(409)->assertJsonPath('message', 'This booking can no longer be paid. Please book again.');
    }
}
