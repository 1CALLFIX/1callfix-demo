<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\RentalFixtureHelpers;
use Tests\TestCase;

/**
 * RENTAL MODULE IMPLEMENTATION -- real HTTP-level coverage for the shared
 * Vehicle/Equipment engine, mirroring PropertyRentalApiTest: public
 * browse, authenticated reservation creation, IDOR-safe "mine"/detail,
 * module-gate enforcement, date-filtered search correctness.
 */
class RentalApiTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;
    use RentalFixtureHelpers;

    public function test_browsing_vehicles_and_equipment_requires_no_authentication(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->makeVehicle($franchise, $zone);
        $this->makeEquipmentItem($franchise, $zone);

        $this->getJson('/api/vehicles')->assertOk()->assertJsonCount(1, 'vehicles');
        $this->getJson('/api/equipment')->assertOk()->assertJsonCount(1, 'equipment');
    }

    public function test_inactive_inventory_does_not_appear_in_browse_or_detail(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $vehicle = $this->makeVehicle($franchise, $zone, null, ['is_active' => false]);

        $this->getJson('/api/vehicles')->assertJsonCount(0, 'vehicles');
        $this->getJson("/api/vehicles/{$vehicle->id}")->assertStatus(404);
    }

    public function test_date_filtered_vehicle_search_finds_an_available_vehicle_outside_the_first_page_window(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateRentalFor($franchise);
        $customer = $this->makeCustomer();

        $bookedVehicle = $this->makeVehicle($franchise, $zone);
        for ($i = 0; $i < 49; $i++) {
            $this->makeVehicle($franchise, $zone);
        }
        $freeVehicle = $this->makeVehicle($franchise, $zone);

        app(\App\Actions\CreateRentalReservationAction::class)->execute([
            'rental_type' => 'vehicle', 'rentable_id' => $bookedVehicle->id, 'customer_id' => $customer->id,
            'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(6),
        ]);

        $response = $this->getJson('/api/vehicles?starts_at='.now()->addDays(4)->toDateString().'&ends_at='.now()->addDays(5)->toDateString())
            ->assertOk();

        $ids = collect($response->json('vehicles'))->pluck('id');
        $this->assertTrue($ids->contains($freeVehicle->id), 'The available vehicle must be found even though it is outside the first 50-by-id window.');
        $this->assertFalse($ids->contains($bookedVehicle->id), 'The booked vehicle must be excluded.');
    }

    public function test_availability_endpoint_reflects_a_real_booked_range(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateRentalFor($franchise);
        $vehicle = $this->makeVehicle($franchise, $zone);
        $customer = $this->makeCustomer();

        app(\App\Actions\CreateRentalReservationAction::class)->execute([
            'rental_type' => 'vehicle', 'rentable_id' => $vehicle->id, 'customer_id' => $customer->id,
            'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(6),
        ]);

        $this->getJson("/api/vehicles/{$vehicle->id}/availability?starts_at=".now()->addDays(4)->toDateString().'&ends_at='.now()->addDays(5)->toDateString())
            ->assertOk()->assertJsonPath('is_available', false);

        $this->getJson("/api/vehicles/{$vehicle->id}/availability?starts_at=".now()->addDays(20)->toDateString().'&ends_at='.now()->addDays(22)->toDateString())
            ->assertOk()->assertJsonPath('is_available', true);
    }

    public function test_reservation_creation_requires_authentication(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $vehicle = $this->makeVehicle($franchise, $zone);

        $this->postJson('/api/rental-reservations', [
            'rental_type' => 'vehicle', 'rentable_id' => $vehicle->id,
            'starts_at' => now()->addDays(3)->toDateTimeString(), 'ends_at' => now()->addDays(5)->toDateTimeString(),
        ])->assertStatus(401);
    }

    public function test_reservation_creation_is_blocked_while_the_module_is_disabled(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $vehicle = $this->makeVehicle($franchise, $zone);
        $customer = $this->makeCustomer();

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/rental-reservations', [
                'rental_type' => 'vehicle', 'rentable_id' => $vehicle->id,
                'starts_at' => now()->addDays(3)->toDateTimeString(), 'ends_at' => now()->addDays(5)->toDateTimeString(),
            ])
            ->assertStatus(422);
    }

    public function test_reservation_creation_succeeds_once_enabled(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateRentalFor($franchise);
        $vehicle = $this->makeVehicle($franchise, $zone);
        $customer = $this->makeCustomer();

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/rental-reservations', [
                'rental_type' => 'vehicle', 'rentable_id' => $vehicle->id,
                'starts_at' => now()->addDays(3)->toDateTimeString(), 'ends_at' => now()->addDays(5)->toDateTimeString(),
            ])
            ->assertStatus(201)
            ->assertJsonPath('reservation.status', 'pending');
    }

    public function test_a_customer_cannot_view_another_customers_reservation_direct_id_manipulation(): void
    {
        $scenario = $this->makeVehicleReservationScenario('pending');
        $otherCustomer = $this->makeCustomer();

        $this->actingAs($otherCustomer, 'sanctum')
            ->getJson("/api/rental-reservations/{$scenario['reservation']->id}")
            ->assertStatus(404);
    }

    public function test_mine_only_returns_the_callers_own_reservations(): void
    {
        $mine = $this->makeVehicleReservationScenario('pending');
        $other = $this->makeVehicleReservationScenario('pending');

        $this->actingAs($mine['customer'], 'sanctum')
            ->getJson('/api/rental-reservations/mine')
            ->assertOk()
            ->assertJsonCount(1, 'reservations')
            ->assertJsonPath('reservations.0.id', $mine['reservation']->id);
    }
}
