<?php

namespace Tests\Feature\Dispatch;

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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PHASE PW1 §1 — verification for the standalone config change
 * `dispatch.offer_timeout_seconds` 25 → 50.
 *
 * Proves the change is a pure `settings` value read at runtime by
 * `ServiceMatchingJob` (no code path hardcodes 25 or 50):
 *   - a round now holds an unanswered offer open for ~50s, not ~25s
 *   - the job re-queues its next round with a matching ~50s delay
 *   - with NO settings row, the in-code fallback is still 25s (so the new
 *     behaviour is entirely Setting-driven, reversible without a deploy)
 *
 * Context builders mirror ServiceMatchingTimeoutReeligibilityTest (kept
 * inline + self-contained, same as that sibling file).
 */
class DispatchOfferTimeoutConfigTest extends TestCase
{
    use RefreshDatabase;

    private static int $countryCodeCounter = 0;

    /** @return array{0: Booking, 1: Zone, 2: Franchise} */
    private function makeBookingContext(string $status = 'pending'): array
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

    private function runRound(Booking $booking, int $round): void
    {
        (new ServiceMatchingJob($booking->id, $round))->handle(app(DispatchService::class));
    }

    // ------------------------------------------------------------------
    // 1. The window: an unanswered offer stays open ~50s, not ~25s.
    // ------------------------------------------------------------------

    public function test_offer_timeout_setting_of_50_keeps_a_45s_old_offer_open_but_times_out_a_55s_old_one(): void
    {
        Queue::fake();
        Setting::set('dispatch.offer_timeout_seconds', '50');

        [$booking, $zone, $franchise] = $this->makeBookingContext('pending');
        $provider = $this->makeProvider($franchise, $zone);

        // Round 1 — sole provider is offered.
        $this->runRound($booking, 1);
        $this->assertDatabaseHas('dispatch_attempts', [
            'booking_id' => $booking->id, 'provider_id' => $provider->id, 'status' => 'notified',
        ]);

        // 45s elapsed — still inside the 50s window. Under the OLD default
        // of 25 this offer would already be a 'timeout'.
        DispatchAttempt::where('booking_id', $booking->id)
            ->update(['notified_at' => now()->subSeconds(45)]);
        $this->runRound($booking, 2);
        $this->assertSame(
            'notified',
            DispatchAttempt::where('booking_id', $booking->id)->value('status'),
            'A 45s-old offer must still be open when the timeout is 50s.'
        );
        $this->assertSame(1, DispatchAttempt::where('booking_id', $booking->id)->count(),
            'No new offer row — the sole provider still holds the open one.');

        // 55s elapsed — past the 50s window. Next round closes it out.
        DispatchAttempt::where('booking_id', $booking->id)
            ->update(['notified_at' => now()->subSeconds(55)]);
        $this->runRound($booking, 3);
        $this->assertSame(1, DispatchAttempt::where('booking_id', $booking->id)->where('status', 'timeout')->count(),
            'A 55s-old offer must be timed out when the timeout is 50s.');
    }

    // ------------------------------------------------------------------
    // 2. The re-queue delay matches the configured window.
    // ------------------------------------------------------------------

    public function test_next_round_is_requeued_with_a_delay_matching_the_configured_timeout(): void
    {
        Queue::fake();
        Setting::set('dispatch.offer_timeout_seconds', '50');

        [$booking, $zone, $franchise] = $this->makeBookingContext('pending');
        $this->makeProvider($franchise, $zone);

        $this->runRound($booking, 1);

        Queue::assertPushed(ServiceMatchingJob::class, function (ServiceMatchingJob $job) {
            if ($job->round !== 2) {
                return false;
            }
            $delaySeconds = Carbon::parse($job->delay)->getTimestamp() - now()->getTimestamp();

            return $delaySeconds >= 47 && $delaySeconds <= 53;
        });
    }

    // ------------------------------------------------------------------
    // 3. Fallback: with no settings row, the in-code default is still 25s.
    // ------------------------------------------------------------------

    public function test_without_a_settings_row_the_in_code_fallback_is_still_25s(): void
    {
        Queue::fake();
        $this->assertNull(Setting::where('key', 'dispatch.offer_timeout_seconds')->value('value'));

        [$booking, $zone, $franchise] = $this->makeBookingContext('pending');
        $provider = $this->makeProvider($franchise, $zone);

        $this->runRound($booking, 1);

        // 30s elapsed — inside a 50s window, but PAST the 25s fallback.
        DispatchAttempt::where('booking_id', $booking->id)
            ->update(['notified_at' => now()->subSeconds(30)]);
        $this->runRound($booking, 2);

        $this->assertSame(1, DispatchAttempt::where('booking_id', $booking->id)->where('status', 'timeout')->count(),
            'With no settings row a 30s-old offer must time out — the fallback is 25s, not 50s.');
    }
}
