<?php

namespace Tests\Feature\Api;

use App\Models\DispatchAttempt;
use App\Models\FieldWorker;
use App\Models\TaxiRide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\TaxiRideFixtureHelpers;
use Tests\TestCase;

/** Phase 22.6 (Taxi). HTTP-level coverage for TaxiWorkerJobController, exact mirror of ParcelWorkerJobApiTest. */
class TaxiWorkerJobApiTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;
    use TaxiRideFixtureHelpers;

    public function test_index_requires_a_field_worker_account(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/worker/taxi-rides')
            ->assertStatus(403);
    }

    public function test_index_only_returns_rides_assigned_to_the_caller(): void
    {
        $mine = $this->makeAssignedTaxiRideScenario();
        $other = $this->makeAssignedTaxiRideScenario();

        $this->actingAs($mine['driver']->user, 'sanctum')
            ->getJson('/api/worker/taxi-rides')
            ->assertOk()
            ->assertJsonPath('taxi_rides.0.id', $mine['ride']->id)
            ->assertJsonCount(1, 'taxi_rides');
    }

    public function test_accept_rejects_a_driver_not_actually_offered_the_ride(): void
    {
        $scenario = $this->makeTaxiRideScenario('searching_driver');
        $uninvited = $this->makeTaxiDriverIn($scenario['franchise'], $scenario['zone']);

        $this->actingAs($uninvited->user, 'sanctum')
            ->postJson("/api/worker/taxi-rides/{$scenario['ride']->id}/accept")
            ->assertStatus(409);

        $this->assertNull($scenario['ride']->fresh()->assigned_worker_id);
    }

    public function test_accept_succeeds_for_the_actually_offered_driver(): void
    {
        $scenario = $this->makeTaxiRideScenario('searching_driver');
        DispatchAttempt::create([
            'dispatchable_type' => TaxiRide::class, 'dispatchable_id' => $scenario['ride']->id,
            'notifiable_type' => FieldWorker::class, 'notifiable_id' => $scenario['driver']->id,
            'status' => 'notified', 'notified_at' => now(),
        ]);

        $this->actingAs($scenario['driver']->user, 'sanctum')
            ->postJson("/api/worker/taxi-rides/{$scenario['ride']->id}/accept")
            ->assertOk()
            ->assertJsonPath('taxi_ride.status', 'assigned');
    }

    public function test_start_rejects_a_driver_the_ride_is_not_assigned_to_direct_id_manipulation(): void
    {
        $scenario = $this->makeAssignedTaxiRideScenario();
        $otherDriver = $this->makeTaxiDriverIn($scenario['franchise'], $scenario['zone']);

        $this->actingAs($otherDriver->user, 'sanctum')
            ->postJson("/api/worker/taxi-rides/{$scenario['ride']->id}/start", ['otp' => '1234'])
            ->assertStatus(403);

        $this->assertSame('assigned', $scenario['ride']->fresh()->status, 'An unauthorized start attempt must never change ride state.');
    }

    public function test_start_succeeds_for_the_assigned_driver_with_correct_otp(): void
    {
        $scenario = $this->makeAssignedTaxiRideScenario();

        $this->actingAs($scenario['driver']->user, 'sanctum')
            ->postJson("/api/worker/taxi-rides/{$scenario['ride']->id}/start", ['otp' => '1234'])
            ->assertOk()
            ->assertJsonPath('taxi_ride.status', 'trip_started');
    }

    public function test_complete_rejects_a_driver_the_ride_is_not_assigned_to(): void
    {
        $scenario = $this->makeAssignedTaxiRideScenario();
        $scenario['ride']->update(['status' => 'trip_started']);
        $otherDriver = $this->makeTaxiDriverIn($scenario['franchise'], $scenario['zone']);

        $this->actingAs($otherDriver->user, 'sanctum')
            ->postJson("/api/worker/taxi-rides/{$scenario['ride']->id}/complete")
            ->assertStatus(403);
    }

    public function test_a_nonexistent_ride_id_returns_403_not_a_stack_trace(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $driver = $this->makeTaxiDriverIn($franchise, $zone);

        $this->actingAs($driver->user, 'sanctum')
            ->postJson('/api/worker/taxi-rides/999999/start', ['otp' => '1234'])
            ->assertStatus(403);
    }
}
