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
use App\Models\User;
use App\Models\Zone;
use App\Services\DispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Priority 2 required test area M (dispatch race) + Phase 10/11 of the QA
 * program. Regression coverage for 1e108ff: ServiceMatchingJob's
 * pending -> searching_provider transition used to read the booking without
 * a lock and write back without a transaction, so a re-dispatch round
 * arriving after a real concurrent AcceptBookingAction could clobber the
 * acceptance back to searching_provider. This had zero automated coverage
 * before this test.
 *
 * PHPUnit is single-threaded, so this can't fire two real concurrent
 * requests — what it CAN and does verify is the actual fix's outcome: the
 * job re-checks booking status under a fresh lock every time handle() runs,
 * so a "late" round that arrives after acceptance already happened must
 * see the up-to-date state and no-op, never overwrite it. That's the
 * property the original bug violated and the fix guarantees, and it's
 * exactly what a real race resolves to once serialized by the row lock.
 */
class ServiceMatchingJobRaceTest extends TestCase
{
    use RefreshDatabase;

    private static int $countryCodeCounter = 0;

    private function makeBookingSearchingForProvider(): array
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
        $providerUser = User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Provider', 'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'provider', 'status' => 'active', 'franchise_id' => $franchise->id,
        ]);
        $provider = Provider::create([
            'user_id' => $providerUser->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'provider_type' => 'independent', 'kyc_status' => 'approved', 'is_active' => true, 'is_online' => true,
        ]);

        $booking = Booking::create([
            'code' => 'TST-'.now()->format('dm').'-'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'customer_id' => $customer->id, 'service_id' => $service->id, 'address_id' => $address->id,
            'status' => 'searching_provider', 'price_quoted' => 300, 'payment_status' => 'pending', 'payment_method' => 'online',
        ]);

        DispatchAttempt::create([
            'booking_id' => $booking->id, 'provider_id' => $provider->id,
            'status' => 'notified', 'distance_km' => 1.0, 'notified_at' => now(),
        ]);

        return [$booking, $provider];
    }

    public function test_a_late_dispatch_round_does_not_clobber_a_real_concurrent_acceptance(): void
    {
        [$booking, $provider] = $this->makeBookingSearchingForProvider();

        // The real acceptance happens first (simulating it winning the race).
        $accepted = app(AcceptBookingAction::class)->execute($booking->id, $provider);
        $this->assertSame('assigned', $accepted->status);
        $this->assertSame($provider->id, $accepted->provider_id);

        // A "late" re-dispatch round for the same booking arrives afterward
        // (simulating the delayed self-requeue firing after the real accept
        // already committed) — reproduces the exact scenario 1e108ff fixed.
        (new ServiceMatchingJob($booking->id, 2))->handle(app(DispatchService::class));

        $booking->refresh();
        $this->assertSame('assigned', $booking->status, 'A late dispatch round must not overwrite a real acceptance.');
        $this->assertSame($provider->id, $booking->provider_id, 'provider_id must survive the late round untouched.');
    }

    public function test_a_late_dispatch_round_does_not_reopen_a_cancelled_booking(): void
    {
        [$booking, $provider] = $this->makeBookingSearchingForProvider();
        $booking->update(['status' => 'cancelled']);

        (new ServiceMatchingJob($booking->id, 2))->handle(app(DispatchService::class));

        $booking->refresh();
        $this->assertSame('cancelled', $booking->status);
        $this->assertNull($booking->provider_id);
    }

    public function test_first_round_transitions_pending_to_searching_and_records_history(): void
    {
        [$booking, $provider] = $this->makeBookingSearchingForProvider();
        $booking->update(['status' => 'pending']);
        // Only the original notified attempt exists; that provider is now
        // "already offered" so findCandidates() will return nothing for
        // this round — fine, this test only verifies the status transition
        // + history write, not full candidate matching.

        (new ServiceMatchingJob($booking->id, 1))->handle(app(DispatchService::class));

        $booking->refresh();
        $this->assertSame('searching_provider', $booking->status);
        $this->assertDatabaseHas('booking_status_history', [
            'booking_id' => $booking->id, 'status' => 'searching_provider',
        ]);
    }

    public function test_job_no_ops_cleanly_when_booking_no_longer_exists(): void
    {
        [$booking] = $this->makeBookingSearchingForProvider();
        $bookingId = $booking->id;
        $booking->forceDelete();

        // Must not throw — this is the "booking was deleted/no longer
        // exists" branch of the same defensive re-check.
        (new ServiceMatchingJob($bookingId, 2))->handle(app(DispatchService::class));

        $this->assertDatabaseMissing('bookings', ['id' => $bookingId]);
    }
}
