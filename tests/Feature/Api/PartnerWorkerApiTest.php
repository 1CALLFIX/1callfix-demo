<?php

namespace Tests\Feature\Api;

use App\Models\Address;
use App\Models\Booking;
use App\Models\FieldWorker;
use App\Models\FieldWorkerCapability;
use App\Models\PartnerWorker;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Mission Phase 16 (API/security/E2E hardening sweep) — POST
 * /api/partner/workers/assign-booking. AssignBookingToWorkerActionTest
 * already proves every boundary at the Action layer; this closes the
 * "0 HTTP-level tests" gap for the one route that actually exposes it,
 * confirming the controller's own profile-existence check and the
 * Action's ownership check both survive the trip through real HTTP/
 * Sanctum/validation middleware.
 */
class PartnerWorkerApiTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->postJson('/api/partner/workers/assign-booking', ['booking_id' => 1, 'field_worker_id' => 1])
            ->assertUnauthorized();
    }

    public function test_a_customer_account_cannot_call_this_endpoint(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/partner/workers/assign-booking', ['booking_id' => 1, 'field_worker_id' => 1])
            ->assertForbidden()
            ->assertJson(['message' => 'Only provider accounts can assign work to a worker.']);
    }

    public function test_partner_can_assign_their_own_accepted_booking_to_their_own_team_worker_via_http(): void
    {
        ['franchise' => $franchise, 'zone' => $zone, 'category' => $category, 'service' => $service, 'provider' => $partner] = $this->makeBookingScenario();
        $partnerUser = $partner->user;
        $worker = $this->makeFieldWorkerIn($franchise, $zone);
        PartnerWorker::create(['provider_id' => $partner->id, 'field_worker_id' => $worker->id, 'status' => 'active']);
        FieldWorkerCapability::create(['field_worker_id' => $worker->id, 'capability_type' => 'service_technician', 'service_category_id' => $category->id]);

        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        $booking = Booking::create([
            'code' => 'TST-'.now()->format('dm').'-'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'customer_id' => $customer->id, 'provider_id' => $partner->id,
            'service_id' => $service->id, 'address_id' => $address->id,
            'status' => 'assigned', 'price_quoted' => 500, 'payment_status' => 'pending', 'payment_method' => 'online',
        ]);

        $response = $this->actingAs($partnerUser, 'sanctum')->postJson('/api/partner/workers/assign-booking', [
            'booking_id' => $booking->id,
            'field_worker_id' => $worker->id,
        ]);

        $response->assertOk()->assertJsonPath('booking.assigned_worker_id', $worker->id);
    }

    public function test_a_partner_cannot_assign_a_booking_accepted_by_a_different_partner_via_http(): void
    {
        ['franchise' => $franchise, 'zone' => $zone, 'provider' => $ownerPartner] = $this->makeBookingScenario();

        $otherUser = User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Other Partner', 'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'provider', 'status' => 'active', 'franchise_id' => $franchise->id,
        ]);
        $otherPartner = Provider::create([
            'user_id' => $otherUser->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'provider_type' => 'independent', 'kyc_status' => 'approved', 'is_active' => true,
        ]);
        $worker = $this->makeFieldWorkerIn($franchise, $zone);
        PartnerWorker::create(['provider_id' => $otherPartner->id, 'field_worker_id' => $worker->id, 'status' => 'active']);

        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        $booking = Booking::create([
            'code' => 'TST-'.now()->format('dm').'-'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'customer_id' => $customer->id, 'provider_id' => $ownerPartner->id, // accepted by the FIRST partner
            'service_id' => $this->makeCategoryAndService()[1]->id, 'address_id' => $address->id,
            'status' => 'assigned', 'price_quoted' => 500, 'payment_status' => 'pending', 'payment_method' => 'online',
        ]);

        // otherPartner (not the accountable provider on this booking) tries
        // to assign it to their own team worker anyway.
        $response = $this->actingAs($otherUser, 'sanctum')->postJson('/api/partner/workers/assign-booking', [
            'booking_id' => $booking->id,
            'field_worker_id' => $worker->id,
        ]);

        $response->assertStatus(409);
        $this->assertNull($booking->fresh()->assigned_worker_id, 'A booking must never be assignable by a partner who did not accept it.');
    }
}
