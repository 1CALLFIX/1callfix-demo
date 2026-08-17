<?php

namespace Tests\Feature\Api;

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
        $this->makeAccommodation($franchise, $zone);

        $this->getJson('/api/hotels')
            ->assertOk()
            ->assertJsonCount(1, 'accommodations');
    }

    public function test_hotel_detail_is_public(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $accommodation = $this->makeAccommodation($franchise, $zone);

        $this->getJson("/api/hotels/{$accommodation->id}")
            ->assertOk()
            ->assertJsonPath('accommodation.id', $accommodation->id);
    }

    public function test_inactive_hotels_do_not_appear_in_browse_or_detail(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $accommodation = $this->makeAccommodation($franchise, $zone, null, ['is_active' => false]);

        $this->getJson('/api/hotels')->assertJsonCount(0, 'accommodations');
        $this->getJson("/api/hotels/{$accommodation->id}")->assertStatus(404);
    }

    public function test_room_types_endpoint_includes_active_rate_plans(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $accommodation = $this->makeAccommodation($franchise, $zone);
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
        $accommodation = $this->makeAccommodation($franchise, $zone);
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
        $accommodation = $this->makeAccommodation($franchise, $zone);
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
        $accommodation = $this->makeAccommodation($franchise, $zone);
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
        $accommodation = $this->makeAccommodation($franchise, $zone);
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
            ->assertJsonPath('reservation.status', 'pending');
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
}
