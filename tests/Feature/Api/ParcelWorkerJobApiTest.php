<?php

namespace Tests\Feature\Api;

use App\Models\DispatchAttempt;
use App\Models\FieldWorker;
use App\Models\ParcelOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\TestCase;

/**
 * Phase 22.4 (Parcel). Real HTTP-level coverage for
 * ParcelWorkerJobController, matching this mission's own established
 * convention (WorkerJobApiTest's own shape) for every genuinely new API
 * surface: auth-required 401/403, real IDOR rejection between two real
 * riders, happy path, direct ID manipulation.
 */
class ParcelWorkerJobApiTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;
    use ParcelOrderFixtureHelpers;

    public function test_index_requires_a_field_worker_account(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/worker/parcel-orders')
            ->assertStatus(403);
    }

    public function test_index_only_returns_orders_assigned_to_the_caller(): void
    {
        $mine = $this->makeAssignedParcelOrderScenario();
        $other = $this->makeAssignedParcelOrderScenario();

        $this->actingAs($mine['rider']->user, 'sanctum')
            ->getJson('/api/worker/parcel-orders')
            ->assertOk()
            ->assertJsonPath('parcel_orders.0.id', $mine['order']->id)
            ->assertJsonCount(1, 'parcel_orders');
    }

    public function test_accept_rejects_a_worker_not_actually_offered_the_order(): void
    {
        $scenario = $this->makeParcelOrderScenario('searching_worker');
        $uninvited = $this->makeParcelRiderIn($scenario['franchise'], $scenario['zone']);

        $this->actingAs($uninvited->user, 'sanctum')
            ->postJson("/api/worker/parcel-orders/{$scenario['order']->id}/accept")
            ->assertStatus(409);

        $this->assertNull($scenario['order']->fresh()->assigned_worker_id);
    }

    public function test_accept_succeeds_for_the_actually_offered_worker(): void
    {
        $scenario = $this->makeParcelOrderScenario('searching_worker');
        DispatchAttempt::create([
            'dispatchable_type' => ParcelOrder::class, 'dispatchable_id' => $scenario['order']->id,
            'notifiable_type' => FieldWorker::class, 'notifiable_id' => $scenario['rider']->id,
            'status' => 'notified', 'notified_at' => now(),
        ]);

        $this->actingAs($scenario['rider']->user, 'sanctum')
            ->postJson("/api/worker/parcel-orders/{$scenario['order']->id}/accept")
            ->assertOk()
            ->assertJsonPath('parcel_order.status', 'assigned');
    }

    public function test_pickup_rejects_a_worker_the_order_is_not_assigned_to_direct_id_manipulation(): void
    {
        $scenario = $this->makeAssignedParcelOrderScenario();
        $otherRider = $this->makeParcelRiderIn($scenario['franchise'], $scenario['zone']);

        // Direct ID manipulation: otherRider knows/guesses a real order id
        // that belongs to someone else and tries to act on it directly.
        $this->actingAs($otherRider->user, 'sanctum')
            ->postJson("/api/worker/parcel-orders/{$scenario['order']->id}/pickup", ['otp' => '1234'])
            ->assertStatus(403);

        $this->assertSame('assigned', $scenario['order']->fresh()->status, 'An unauthorized pickup attempt must never change the order state.');
    }

    public function test_pickup_succeeds_for_the_assigned_worker_with_correct_otp(): void
    {
        $scenario = $this->makeAssignedParcelOrderScenario();

        $this->actingAs($scenario['rider']->user, 'sanctum')
            ->postJson("/api/worker/parcel-orders/{$scenario['order']->id}/pickup", ['otp' => '1234'])
            ->assertOk()
            ->assertJsonPath('parcel_order.status', 'picked_up');
    }

    public function test_deliver_rejects_the_wrong_otp(): void
    {
        $scenario = $this->makeAssignedParcelOrderScenario();
        $scenario['order']->update(['status' => 'picked_up']);

        $this->actingAs($scenario['rider']->user, 'sanctum')
            ->postJson("/api/worker/parcel-orders/{$scenario['order']->id}/deliver", ['otp' => '0000'])
            ->assertStatus(409);
    }

    public function test_a_nonexistent_order_id_returns_403_not_a_stack_trace(): void
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $rider = $this->makeParcelRiderIn($franchise, $zone);

        $this->actingAs($rider->user, 'sanctum')
            ->postJson('/api/worker/parcel-orders/999999/pickup', ['otp' => '1234'])
            ->assertStatus(403);
    }
}
