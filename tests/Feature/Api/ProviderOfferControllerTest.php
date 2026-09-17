<?php

namespace Tests\Feature\Api;

use App\Models\DispatchAttempt;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * REF 1CF-PHASE01-TASK06A — GET /api/provider/offers, the read-only
 * counterpart to DispatchApiTest's accept/complete routes: lists this
 * provider's live 'notified' dispatch_attempts. Same
 * $request->user()->providerProfile resolution and 403-on-no-profile
 * pattern as DispatchController.
 */
class ProviderOfferControllerTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;

    public function test_authorized_provider_receives_own_notified_offer(): void
    {
        ['booking' => $booking, 'provider' => $provider] = $this->makeBookingScenario('searching_provider');
        $attempt = DispatchAttempt::create([
            'booking_id' => $booking->id, 'provider_id' => $provider->id,
            'status' => 'notified', 'distance_km' => 3.5, 'notified_at' => now(),
        ]);

        $response = $this->actingAs($provider->user, 'sanctum')->getJson('/api/provider/offers');

        $response->assertOk();
        $response->assertJsonCount(1, 'offers');
        $response->assertJsonPath('offers.0.booking_id', $booking->id);
        $response->assertJsonPath('offers.0.dispatch_attempt_id', $attempt->id);
        $response->assertJsonPath('offers.0.booking_code', $booking->code);
    }

    public function test_provider_does_not_receive_another_providers_offer(): void
    {
        ['booking' => $booking, 'provider' => $providerA, 'franchise' => $franchise, 'zone' => $zone] = $this->makeBookingScenario('searching_provider');
        $providerB = $this->makeProviderIn($franchise, $zone);

        DispatchAttempt::create([
            'booking_id' => $booking->id, 'provider_id' => $providerA->id,
            'status' => 'notified', 'distance_km' => 1.0, 'notified_at' => now(),
        ]);

        $this->actingAs($providerB->user, 'sanctum')
            ->getJson('/api/provider/offers')
            ->assertOk()
            ->assertJsonCount(0, 'offers');
    }

    public function test_customer_receives_403(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/provider/offers')
            ->assertForbidden()
            ->assertJson(['message' => 'Only provider accounts can view job offers.']);
    }

    public function test_non_notified_attempt_does_not_appear(): void
    {
        ['booking' => $booking, 'provider' => $provider] = $this->makeBookingScenario('searching_provider');
        DispatchAttempt::create([
            'booking_id' => $booking->id, 'provider_id' => $provider->id,
            'status' => 'rejected', 'distance_km' => 1.0, 'notified_at' => now(), 'responded_at' => now(),
        ]);

        $this->actingAs($provider->user, 'sanctum')
            ->getJson('/api/provider/offers')
            ->assertOk()
            ->assertJsonCount(0, 'offers');
    }

    public function test_timeout_attempt_does_not_appear(): void
    {
        ['booking' => $booking, 'provider' => $provider] = $this->makeBookingScenario('searching_provider');
        DispatchAttempt::create([
            'booking_id' => $booking->id, 'provider_id' => $provider->id,
            'status' => 'timeout', 'distance_km' => 1.0,
            'notified_at' => now()->subMinutes(5), 'responded_at' => now(),
        ]);

        $this->actingAs($provider->user, 'sanctum')
            ->getJson('/api/provider/offers')
            ->assertOk()
            ->assertJsonCount(0, 'offers');
    }

    public function test_booking_not_in_searching_provider_does_not_appear(): void
    {
        ['booking' => $booking, 'provider' => $provider] = $this->makeBookingScenario('pending');
        DispatchAttempt::create([
            'booking_id' => $booking->id, 'provider_id' => $provider->id,
            'status' => 'notified', 'distance_km' => 1.0, 'notified_at' => now(),
        ]);

        $this->actingAs($provider->user, 'sanctum')
            ->getJson('/api/provider/offers')
            ->assertOk()
            ->assertJsonCount(0, 'offers');
    }

    public function test_accepted_booking_no_longer_appears_as_an_offer(): void
    {
        ['booking' => $booking, 'provider' => $provider, 'category' => $category] = $this->makeBookingScenario('searching_provider');
        // REF 1CF-LAUNCH-011A-FIX — AcceptBookingAction now re-checks real
        // eligibility (REF 1CF-LAUNCH-011), so this provider must genuinely
        // qualify or the /accept call below correctly 409s instead of
        // reaching the "no longer an offer" state this test exists to prove.
        $provider = $this->makeEligible($provider, $category->id);
        DispatchAttempt::create([
            'booking_id' => $booking->id, 'provider_id' => $provider->id,
            'status' => 'notified', 'distance_km' => 1.0, 'notified_at' => now(),
        ]);

        $this->actingAs($provider->user, 'sanctum')->postJson("/api/bookings/{$booking->id}/accept")->assertOk();

        $this->actingAs($provider->user, 'sanctum')
            ->getJson('/api/provider/offers')
            ->assertOk()
            ->assertJsonCount(0, 'offers');
    }

    public function test_provider_with_no_offers_receives_empty_collection(): void
    {
        ['franchise' => $franchise, 'zone' => $zone] = $this->makeBookingScenario('searching_provider');
        $provider = $this->makeProviderIn($franchise, $zone);

        $this->actingAs($provider->user, 'sanctum')
            ->getJson('/api/provider/offers')
            ->assertOk()
            ->assertExactJson(['offers' => []]);
    }

    public function test_offer_response_contains_only_approved_fields(): void
    {
        ['booking' => $booking, 'provider' => $provider] = $this->makeBookingScenario('searching_provider');
        DispatchAttempt::create([
            'booking_id' => $booking->id, 'provider_id' => $provider->id,
            'status' => 'notified', 'distance_km' => 1.0, 'notified_at' => now(),
        ]);

        $response = $this->actingAs($provider->user, 'sanctum')->getJson('/api/provider/offers');

        $response->assertOk();
        $offer = $response->json('offers.0');

        $this->assertEqualsCanonicalizing([
            'booking_id', 'dispatch_attempt_id', 'booking_code', 'service_name',
            'address_summary', 'price_quoted', 'distance_km', 'notified_at', 'offer_expires_at',
        ], array_keys($offer));
    }

    public function test_offer_expires_at_uses_the_existing_dispatch_offer_timeout_setting(): void
    {
        ['booking' => $booking, 'provider' => $provider] = $this->makeBookingScenario('searching_provider');
        // Global scope, matching the controller's own two-arg (unscoped)
        // Setting::get() call — same convention ServiceMatchingJob and the
        // provider portal already use for this key.
        Setting::set('dispatch.offer_timeout_seconds', '40');

        $notifiedAt = now();
        DispatchAttempt::create([
            'booking_id' => $booking->id, 'provider_id' => $provider->id,
            'status' => 'notified', 'distance_km' => 1.0, 'notified_at' => $notifiedAt,
        ]);

        $response = $this->actingAs($provider->user, 'sanctum')->getJson('/api/provider/offers');

        $response->assertOk();
        $expiresAt = $response->json('offers.0.offer_expires_at');
        $this->assertNotNull($expiresAt);
        $this->assertSame(
            $notifiedAt->copy()->addSeconds(40)->toIso8601String(),
            \Illuminate\Support\Carbon::parse($expiresAt)->toIso8601String()
        );
    }

    public function test_no_provider_id_can_be_supplied_by_the_client_to_access_another_providers_offer(): void
    {
        ['booking' => $booking, 'provider' => $providerA, 'franchise' => $franchise, 'zone' => $zone] = $this->makeBookingScenario('searching_provider');
        $providerB = $this->makeProviderIn($franchise, $zone);

        DispatchAttempt::create([
            'booking_id' => $booking->id, 'provider_id' => $providerA->id,
            'status' => 'notified', 'distance_km' => 1.0, 'notified_at' => now(),
        ]);

        $this->actingAs($providerB->user, 'sanctum')
            ->getJson('/api/provider/offers?provider_id='.$providerA->id)
            ->assertOk()
            ->assertJsonCount(0, 'offers');
    }

    public function test_malformed_or_missing_authentication_is_rejected_normally(): void
    {
        $this->getJson('/api/provider/offers')->assertUnauthorized();

        $this->withHeaders(['Authorization' => 'Bearer not-a-real-token'])
            ->getJson('/api/provider/offers')
            ->assertUnauthorized();
    }
}
