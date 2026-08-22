<?php

namespace Tests\Feature\Dispatch;

use App\Actions\AcceptBookingAction;
use App\Jobs\ServiceMatchingJob;
use App\Models\Address;
use App\Models\Booking;
use App\Models\City;
use App\Models\Country;
use App\Models\DispatchAttempt;
use App\Models\Franchise;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Setting;
use App\Models\User;
use App\Models\Zone;
use App\Services\DispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression coverage for the "single-provider dispatch trap" (real
 * incident: booking #136 / NLR-2208-00000076). Before this fix,
 * DispatchService::findCandidates() excluded a provider from every future
 * round of a booking the moment ANY dispatch_attempts row existed for them
 * — no matter its status. In a zone with exactly one available provider, a
 * single missed offer window (a 'timeout', not an explicit decline) meant
 * the booking could never be auto-assigned again.
 *
 * The fix (see DispatchService::excludedProviderIdsForBooking()):
 *   - 'timeout'            -> NOT permanently excluded; re-eligible in a
 *                              later round, up to dispatch.max_timeouts_per_provider
 *                              times on the SAME booking (circuit breaker).
 *   - 'rejected'            -> permanently excluded (explicit decline). No UI
 *                              path writes this status today (confirmed by
 *                              reading every write path — see the method's
 *                              own docblock), but the schema has always
 *                              defined it and the exclusion logic honors it.
 *   - 'notified'/'accepted' -> excluded (open offer / already won), unchanged.
 */
class ServiceMatchingTimeoutReeligibilityTest extends TestCase
{
    use RefreshDatabase;

    private static int $countryCodeCounter = 0;

    /** @return array{0: Booking, 1: Zone, 2: Franchise} */
    private function makeBookingContext(string $status = 'searching_provider'): array
    {
        $countryCode = strtoupper(str_pad(base_convert((string) self::$countryCodeCounter++, 10, 36), 2, '0', STR_PAD_LEFT));
        $country = Country::create(['name' => 'Testland', 'code' => $countryCode, 'currency_code' => 'INR', 'default_timezone' => 'Asia/Kolkata', 'is_active' => true]);
        $city = City::create(['country_id' => $country->id, 'name' => 'City '.Str::random(6), 'is_active' => true]);
        $franchise = Franchise::create([
            'name' => 'Franchise', 'slug' => Str::slug('franchise-'.Str::random(8)),
            'city' => $city->name, 'country_id' => $country->id, 'city_id' => $city->id, 'status' => 'active',
        ]);
        $zone = Zone::create([
            'franchise_id' => $franchise->id, 'name' => 'Zone',
            'boundary_polygon' => [['lat' => 1, 'lng' => 1], ['lat' => 2, 'lng' => 2], ['lat' => 3, 'lng' => 3]],
            'is_active' => true, 'default_dispatch_radius_km' => 8,
        ]);
        $category = ServiceCategory::create([
            'module' => 'service', 'name' => 'Cat', 'slug' => 'cat-'.Str::random(6),
            'image' => 'categories/x.png', 'sort_order' => 1, 'is_active' => true,
        ]);
        $service = Service::create([
            'category_id' => $category->id, 'name' => 'Svc', 'slug' => 'svc-'.Str::random(6),
            'base_price' => 300, 'price_type' => 'fixed', 'duration_estimate_mins' => 30,
            'is_active' => true, 'location_required' => true, 'age_restriction' => false, 'sort_order' => 1,
        ]);
        $customer = User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Customer', 'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'customer', 'status' => 'active',
        ]);
        $address = Address::create([
            'user_id' => $customer->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'label' => 'Home', 'lat' => 1.0, 'lng' => 1.0, 'address_line' => 'Addr',
        ]);

        $booking = Booking::create([
            'code' => 'TST-'.now()->format('dm').'-'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'customer_id' => $customer->id, 'service_id' => $service->id, 'address_id' => $address->id,
            'status' => $status, 'price_quoted' => 300, 'payment_status' => 'pending', 'payment_method' => 'online',
        ]);

        return [$booking->fresh(), $zone, $franchise];
    }

    private function makeProvider(Franchise $franchise, Zone $zone, float $lat = 1.0, float $lng = 1.0): Provider
    {
        $categoryId = Service::first()->category_id;

        $providerUser = User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Provider '.Str::random(4), 'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'provider', 'status' => 'active', 'franchise_id' => $franchise->id,
        ]);

        return Provider::create([
            'user_id' => $providerUser->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'provider_type' => 'independent', 'kyc_status' => 'approved', 'is_active' => true, 'is_online' => true,
            'current_lat' => $lat, 'current_lng' => $lng, 'skills' => [$categoryId],
        ]);
    }

    // -----------------------------------------------------------------
    // 1. Single-provider zone: timeout -> re-eligible next round -> accepts
    // -----------------------------------------------------------------

    public function test_timed_out_provider_in_single_provider_zone_is_reoffered_next_round_and_can_accept(): void
    {
        Queue::fake();

        [$booking, $zone, $franchise] = $this->makeBookingContext('pending');
        $provider = $this->makeProvider($franchise, $zone);

        // Round 1: real job run, offers the sole provider.
        (new ServiceMatchingJob($booking->id, 1))->handle(app(DispatchService::class));

        $this->assertDatabaseHas('dispatch_attempts', [
            'booking_id' => $booking->id, 'provider_id' => $provider->id, 'status' => 'notified',
        ]);

        // Simulate the offer window expiring without a response — push
        // notified_at into the past, then run round 2 (this is exactly what
        // the self-requeued job does; called directly here for a
        // deterministic test instead of relying on the queue fake's delay).
        DispatchAttempt::where('booking_id', $booking->id)->where('provider_id', $provider->id)
            ->update(['notified_at' => now()->subSeconds(60)]);

        (new ServiceMatchingJob($booking->id, 2))->handle(app(DispatchService::class));

        // The round-1 attempt is now closed out as a timeout...
        $this->assertDatabaseHas('dispatch_attempts', [
            'booking_id' => $booking->id, 'provider_id' => $provider->id, 'status' => 'timeout',
        ]);

        // ...and the SAME (only) provider was re-offered in round 2 — this
        // is the actual bug fix: previously findCandidates() would have
        // excluded them forever and round 2 would find zero candidates.
        $this->assertSame(2, DispatchAttempt::where('booking_id', $booking->id)->where('provider_id', $provider->id)->count());
        $this->assertDatabaseHas('dispatch_attempts', [
            'booking_id' => $booking->id, 'provider_id' => $provider->id, 'status' => 'notified',
        ]);

        // Booking proceeds normally: the provider accepts the round-2 offer.
        $accepted = app(AcceptBookingAction::class)->execute($booking->id, $provider);
        $this->assertSame('assigned', $accepted->status);
        $this->assertSame($provider->id, $accepted->provider_id);
    }

    // -----------------------------------------------------------------
    // 2. Explicit decline (status = 'rejected') still permanently excludes
    // -----------------------------------------------------------------

    public function test_explicitly_rejected_provider_is_never_reoffered_even_though_timeouts_would_be(): void
    {
        [$booking, $zone, $franchise] = $this->makeBookingContext();
        $rejecting = $this->makeProvider($franchise, $zone, 1.0, 1.0);
        $clean = $this->makeProvider($franchise, $zone, 1.01, 1.01);

        DispatchAttempt::create([
            'booking_id' => $booking->id, 'provider_id' => $rejecting->id,
            'status' => 'rejected', 'distance_km' => 1.0, 'notified_at' => now()->subMinutes(5), 'responded_at' => now()->subMinutes(4),
        ]);

        $candidateIds = app(DispatchService::class)->findCandidates($booking, 5)->pluck('provider.id');

        $this->assertTrue($candidateIds->contains($clean->id), 'The unrelated clean provider must still be offered the job.');
        $this->assertFalse($candidateIds->contains($rejecting->id), 'A provider who explicitly declined must stay excluded for this booking.');
    }

    // -----------------------------------------------------------------
    // 3. Circuit breaker: repeated timeouts on the SAME booking eventually
    //    stop that specific provider from being re-offered it.
    // -----------------------------------------------------------------

    public function test_provider_below_timeout_threshold_stays_eligible_but_trips_breaker_at_threshold(): void
    {
        [$booking, $zone, $franchise] = $this->makeBookingContext();
        $provider = $this->makeProvider($franchise, $zone);

        $threshold = (int) Setting::get('dispatch.max_timeouts_per_provider', 3);
        $this->assertGreaterThan(1, $threshold, 'Sanity check on the configured default.');

        // One fewer timeout than the breaker threshold: still eligible.
        for ($i = 0; $i < $threshold - 1; $i++) {
            DispatchAttempt::create([
                'booking_id' => $booking->id, 'provider_id' => $provider->id,
                'status' => 'timeout', 'distance_km' => 1.0,
                'notified_at' => now()->subMinutes(10), 'responded_at' => now()->subMinutes(9),
            ]);
        }

        $candidateIds = app(DispatchService::class)->findCandidates($booking, 5)->pluck('provider.id');
        $this->assertTrue($candidateIds->contains($provider->id), 'Below the circuit-breaker threshold, the provider must still be eligible.');

        // One more timeout reaches the threshold — breaker trips for THIS
        // booking/provider pair specifically.
        DispatchAttempt::create([
            'booking_id' => $booking->id, 'provider_id' => $provider->id,
            'status' => 'timeout', 'distance_km' => 1.0,
            'notified_at' => now()->subMinutes(1), 'responded_at' => now(),
        ]);

        $candidateIds = app(DispatchService::class)->findCandidates($booking, 5)->pluck('provider.id');
        $this->assertFalse($candidateIds->contains($provider->id), 'At the circuit-breaker threshold, this booking must stop re-offering this specific provider.');
    }

    public function test_circuit_breaker_only_stops_the_offending_provider_not_the_whole_booking(): void
    {
        [$booking, $zone, $franchise] = $this->makeBookingContext();
        $chronicallyUnresponsive = $this->makeProvider($franchise, $zone, 1.0, 1.0);
        $fresh = $this->makeProvider($franchise, $zone, 1.01, 1.01);

        $threshold = (int) Setting::get('dispatch.max_timeouts_per_provider', 3);
        for ($i = 0; $i < $threshold; $i++) {
            DispatchAttempt::create([
                'booking_id' => $booking->id, 'provider_id' => $chronicallyUnresponsive->id,
                'status' => 'timeout', 'distance_km' => 1.0,
                'notified_at' => now()->subMinutes(10), 'responded_at' => now()->subMinutes(9),
            ]);
        }

        $candidateIds = app(DispatchService::class)->findCandidates($booking, 5)->pluck('provider.id');

        $this->assertFalse($candidateIds->contains($chronicallyUnresponsive->id), 'The circuit-broken provider must be excluded.');
        $this->assertTrue($candidateIds->contains($fresh->id), 'A different, never-offered provider in the same zone must still be considered — the booking itself keeps searching.');
    }

    // -----------------------------------------------------------------
    // 4. Multi-provider zone: unaffected by this change.
    // -----------------------------------------------------------------

    public function test_multi_provider_zone_ranking_and_limit_behavior_is_unchanged(): void
    {
        [$booking, $zone, $franchise] = $this->makeBookingContext();
        $near = $this->makeProvider($franchise, $zone, 1.001, 1.001);
        $far = $this->makeProvider($franchise, $zone, 1.05, 1.05);

        $candidates = app(DispatchService::class)->findCandidates($booking, 5);
        $candidateIds = $candidates->pluck('provider.id');

        $this->assertTrue($candidateIds->contains($near->id));
        $this->assertTrue($candidateIds->contains($far->id));
        // Nearest first — ranking untouched by the exclusion-logic change.
        $this->assertSame($near->id, $candidates->first()['provider']->id);
    }
}
