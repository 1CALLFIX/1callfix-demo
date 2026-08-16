<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\PropertyRentalFixtureHelpers;
use Tests\TestCase;

/**
 * Phase 22.7 (Property Rental). Real HTTP-level coverage: public browse
 * (no auth required), authenticated reservation creation, IDOR-safe
 * "my reservations"/detail, module-gate enforcement at the API layer.
 */
class PropertyRentalApiTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;
    use PropertyRentalFixtureHelpers;

    public function test_browsing_properties_requires_no_authentication(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->makeProperty($franchise, $zone);

        $this->getJson('/api/properties')
            ->assertOk()
            ->assertJsonCount(1, 'properties');
    }

    public function test_property_detail_is_public(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $property = $this->makeProperty($franchise, $zone);

        $this->getJson("/api/properties/{$property->id}")
            ->assertOk()
            ->assertJsonPath('property.id', $property->id);
    }

    public function test_inactive_properties_do_not_appear_in_browse_or_detail(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $property = $this->makeProperty($franchise, $zone, null, ['is_active' => false]);

        $this->getJson('/api/properties')->assertJsonCount(0, 'properties');
        $this->getJson("/api/properties/{$property->id}")->assertStatus(404);
    }

    public function test_availability_endpoint_reflects_a_real_booked_range(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateCarRentalFor($franchise);
        $property = $this->makeProperty($franchise, $zone);
        $customer = $this->makeCustomer();

        app(\App\Actions\CreatePropertyReservationAction::class)->execute([
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id, 'customer_id' => $customer->id,
            'property_id' => $property->id, 'check_in_date' => now()->addDays(3)->toDateString(), 'check_out_date' => now()->addDays(6)->toDateString(),
        ]);

        $this->getJson("/api/properties/{$property->id}/availability?check_in=".now()->addDays(4)->toDateString()."&check_out=".now()->addDays(5)->toDateString())
            ->assertOk()
            ->assertJsonPath('is_available', false);

        $this->getJson("/api/properties/{$property->id}/availability?check_in=".now()->addDays(20)->toDateString()."&check_out=".now()->addDays(22)->toDateString())
            ->assertOk()
            ->assertJsonPath('is_available', true);
    }

    public function test_reservation_creation_requires_authentication(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $property = $this->makeProperty($franchise, $zone);

        $this->postJson("/api/properties/{$property->id}/reservations", [
            'check_in_date' => now()->addDays(3)->toDateString(), 'check_out_date' => now()->addDays(5)->toDateString(),
        ])->assertStatus(401);
    }

    public function test_reservation_creation_is_blocked_while_the_module_is_disabled(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $property = $this->makeProperty($franchise, $zone);
        $customer = $this->makeCustomer();

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/properties/{$property->id}/reservations", [
                'check_in_date' => now()->addDays(3)->toDateString(), 'check_out_date' => now()->addDays(5)->toDateString(),
            ])
            ->assertStatus(422);
    }

    public function test_reservation_creation_succeeds_once_enabled(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateCarRentalFor($franchise);
        $property = $this->makeProperty($franchise, $zone);
        $customer = $this->makeCustomer();

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/properties/{$property->id}/reservations", [
                'check_in_date' => now()->addDays(3)->toDateString(), 'check_out_date' => now()->addDays(5)->toDateString(),
            ])
            ->assertStatus(201)
            ->assertJsonPath('reservation.status', 'pending');
    }

    public function test_a_customer_cannot_view_another_customers_reservation_direct_id_manipulation(): void
    {
        $scenario = $this->makePropertyReservationScenario('pending');
        $otherCustomer = $this->makeCustomer();

        $this->actingAs($otherCustomer, 'sanctum')
            ->getJson("/api/reservations/{$scenario['reservation']->id}")
            ->assertStatus(404);
    }

    public function test_mine_only_returns_the_callers_own_reservations(): void
    {
        $mine = $this->makePropertyReservationScenario('pending');
        $other = $this->makePropertyReservationScenario('pending');

        $this->actingAs($mine['customer'], 'sanctum')
            ->getJson('/api/reservations/mine')
            ->assertOk()
            ->assertJsonCount(1, 'reservations')
            ->assertJsonPath('reservations.0.id', $mine['reservation']->id);
    }
}
