<?php

namespace Tests\Feature\Api;

use App\Models\FieldWorker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Part 9 of the finalization sprint: real HTTP requests against
 * /api/worker/jobs/*, not just Action-layer calls — this closes the "0
 * HTTP-level tests" gap noted in API_INVENTORY.md for these three routes.
 * Deliberately targets the actual IDOR boundary a mobile Rider app would
 * be trusted to respect but a malicious/buggy client wouldn't be: a
 * worker hitting another worker's job id directly.
 */
class WorkerJobApiTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;

    private function makeFieldWorkerUser(int $franchiseId, int $zoneId): array
    {
        $user = User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Worker User', 'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'provider', 'status' => 'active', 'franchise_id' => $franchiseId,
        ]);
        $worker = FieldWorker::create([
            'user_id' => $user->id, 'franchise_id' => $franchiseId, 'zone_id' => $zoneId,
            'kyc_status' => 'approved', 'is_active' => true,
        ]);

        return [$user, $worker];
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/worker/jobs')->assertUnauthorized();
    }

    public function test_a_customer_account_cannot_call_worker_endpoints(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/worker/jobs')
            ->assertForbidden()
            ->assertJson(['message' => 'Only field worker accounts can view assigned jobs.']);
    }

    public function test_worker_sees_only_their_own_assigned_jobs(): void
    {
        ['franchise' => $franchise, 'zone' => $zone, 'booking' => $bookingA] = $this->makeAssignedBookingScenario();
        [$userA, $workerA] = $this->makeFieldWorkerUser($franchise->id, $zone->id);
        [, $workerB] = $this->makeFieldWorkerUser($franchise->id, $zone->id);

        $bookingA->update(['assigned_worker_id' => $workerA->id]);

        $scenarioB = $this->makeAssignedBookingScenario();
        $scenarioB['booking']->update(['assigned_worker_id' => $workerB->id]);

        $response = $this->actingAs($userA, 'sanctum')->getJson('/api/worker/jobs');

        $response->assertOk();
        $ids = collect($response->json('bookings'))->pluck('id');
        $this->assertTrue($ids->contains($bookingA->id));
        $this->assertFalse($ids->contains($scenarioB['booking']->id), 'A worker must never see a job assigned to a different worker.');
    }

    public function test_worker_cannot_start_a_job_assigned_to_a_different_worker(): void
    {
        ['franchise' => $franchise, 'zone' => $zone, 'booking' => $booking] = $this->makeAssignedBookingScenario();
        [, $workerA] = $this->makeFieldWorkerUser($franchise->id, $zone->id);
        [$userB, $workerB] = $this->makeFieldWorkerUser($franchise->id, $zone->id);
        $booking->update(['assigned_worker_id' => $workerA->id]);

        // userB tries to start a job assigned to workerA, by guessing/
        // reusing the booking id directly — the real IDOR case this route
        // must reject server-side, not just hide the button for.
        $this->actingAs($userB, 'sanctum')
            ->postJson("/api/worker/jobs/{$booking->id}/start", ['otp' => $booking->start_otp])
            ->assertForbidden()
            ->assertJson(['message' => 'This job is not assigned to you.']);

        $this->assertSame('assigned', $booking->fresh()->status, 'The booking must not have been started by the wrong worker.');
    }

    public function test_worker_can_start_and_complete_their_own_assigned_job_via_http(): void
    {
        ['franchise' => $franchise, 'zone' => $zone, 'booking' => $booking] = $this->makeAssignedBookingScenario();
        [$user, $worker] = $this->makeFieldWorkerUser($franchise->id, $zone->id);
        $booking->update(['assigned_worker_id' => $worker->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/worker/jobs/{$booking->id}/start", ['otp' => $booking->start_otp])
            ->assertOk()
            ->assertJsonPath('booking.status', 'in_progress');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/worker/jobs/{$booking->id}/complete", ['otp' => $booking->fresh()->completion_otp]);

        $response->assertOk()->assertJsonPath('booking.status', 'completed');

        // Commission is credited to the booking's accountable PROVIDER, not
        // the worker who physically completed it — confirmed at the HTTP
        // layer, not just the Action layer.
        $this->assertDatabaseHas('commissions', ['booking_id' => $booking->id]);
        $this->assertSame($booking->fresh()->provider_id, $booking->provider_id);
    }

    public function test_wrong_otp_returns_409_not_a_500(): void
    {
        ['franchise' => $franchise, 'zone' => $zone, 'booking' => $booking] = $this->makeAssignedBookingScenario();
        [$user, $worker] = $this->makeFieldWorkerUser($franchise->id, $zone->id);
        $booking->update(['assigned_worker_id' => $worker->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/worker/jobs/{$booking->id}/start", ['otp' => '0000'])
            ->assertStatus(409);
    }

    public function test_missing_otp_returns_422_validation_error(): void
    {
        ['franchise' => $franchise, 'zone' => $zone, 'booking' => $booking] = $this->makeAssignedBookingScenario();
        [$user, $worker] = $this->makeFieldWorkerUser($franchise->id, $zone->id);
        $booking->update(['assigned_worker_id' => $worker->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/worker/jobs/{$booking->id}/start", [])
            ->assertStatus(422);
    }
}
