<?php

namespace Tests\Feature\Api;

use App\Models\DispatchAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Part 9 of the finalization sprint: real HTTP requests against
 * /api/bookings/{booking}/accept and /complete — the actual Partner-app
 * facing routes, not just AcceptBookingAction/CompleteBookingAction called
 * directly.
 */
class DispatchApiTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;

    public function test_unauthenticated_accept_is_rejected(): void
    {
        ['booking' => $booking] = $this->makeBookingScenario('searching_provider');

        $this->postJson("/api/bookings/{$booking->id}/accept")->assertUnauthorized();
    }

    public function test_a_customer_account_cannot_accept_a_job(): void
    {
        $customer = $this->makeCustomer();
        ['booking' => $booking] = $this->makeBookingScenario('searching_provider');

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/accept")
            ->assertForbidden()
            ->assertJson(['message' => 'Only provider accounts can accept jobs.']);
    }

    public function test_provider_can_accept_a_real_offer_via_http(): void
    {
        ['booking' => $booking, 'provider' => $provider] = $this->makeBookingScenario('searching_provider');
        DispatchAttempt::create([
            'booking_id' => $booking->id, 'provider_id' => $provider->id,
            'status' => 'notified', 'distance_km' => 1.0, 'notified_at' => now(),
        ]);

        $response = $this->actingAs($provider->user, 'sanctum')->postJson("/api/bookings/{$booking->id}/accept");

        $response->assertOk()->assertJsonPath('booking.status', 'assigned');
        $this->assertNotEmpty($response->json('booking.start_otp'));
    }

    public function test_accepting_an_offer_that_was_never_made_returns_409(): void
    {
        // The exact route-level analogue of the offer-hijacking case: a
        // provider who was never actually offered this booking (no
        // 'notified' dispatch_attempts row) hits the route directly.
        ['booking' => $booking, 'provider' => $provider] = $this->makeBookingScenario('searching_provider');

        $this->actingAs($provider->user, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/accept")
            ->assertStatus(409)
            ->assertJson(['message' => 'This job offer is no longer available (expired or already withdrawn).']);
    }

    public function test_second_provider_accepting_an_already_assigned_booking_gets_409_not_a_silent_double_assign(): void
    {
        ['booking' => $booking, 'provider' => $providerA, 'franchise' => $franchise, 'zone' => $zone] = $this->makeBookingScenario('searching_provider');
        $providerB = $this->makeProviderIn($franchise, $zone);

        DispatchAttempt::create(['booking_id' => $booking->id, 'provider_id' => $providerA->id, 'status' => 'notified', 'distance_km' => 1.0, 'notified_at' => now()]);
        DispatchAttempt::create(['booking_id' => $booking->id, 'provider_id' => $providerB->id, 'status' => 'notified', 'distance_km' => 2.0, 'notified_at' => now()]);

        $this->actingAs($providerA->user, 'sanctum')->postJson("/api/bookings/{$booking->id}/accept")->assertOk();

        $this->actingAs($providerB->user, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/accept")
            ->assertStatus(409)
            ->assertJson(['message' => 'This job has already been assigned to another provider.']);

        $this->assertSame($providerA->id, $booking->fresh()->provider_id);
    }

    public function test_provider_can_complete_their_own_job_via_http_and_commission_is_credited(): void
    {
        ['booking' => $booking, 'provider' => $provider] = $this->makeAssignedBookingScenario();

        $response = $this->actingAs($provider->user, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/complete", ['otp' => $booking->completion_otp]);

        $response->assertOk()->assertJsonPath('booking.status', 'completed');
        $this->assertDatabaseHas('commissions', ['booking_id' => $booking->id]);
    }

    public function test_wrong_completion_otp_returns_409(): void
    {
        ['booking' => $booking, 'provider' => $provider] = $this->makeAssignedBookingScenario();

        $this->actingAs($provider->user, 'sanctum')
            ->postJson("/api/bookings/{$booking->id}/complete", ['otp' => '0000'])
            ->assertStatus(409);
    }
}
