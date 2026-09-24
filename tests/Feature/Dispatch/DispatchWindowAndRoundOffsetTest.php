<?php

namespace Tests\Feature\Dispatch;

use App\Jobs\ServiceMatchingJob;
use App\Livewire\Settings\Manage as SettingsManage;
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
use Livewire\Livewire;
use Tests\TestCase;

/**
 * REF 1CF-DISPATCH-20260924-WINDOW-AND-RANKING (finding D-02).
 *
 * Before: every round re-ranked the eligible pool identically, so the same
 * top batch was re-offered each round until the 3-miss circuit breaker
 * tripped — 5 × (6 rounds / 3 misses) = 10 distinct providers per booking,
 * however many were eligible, inside a 6 × 25s = 2.5 minute window.
 *
 * After: rounds prefer providers never offered this booking (round offset),
 * and the default window is 24 rounds × 25s = 10 minutes.
 */
class DispatchWindowAndRoundOffsetTest extends TestCase
{
    use RefreshDatabase;

    private static int $countryCodeCounter = 0;

    /** @return array{0: Booking, 1: Zone, 2: Franchise} */
    private function makeBookingContext(): array
    {
        $countryCode = 'W'.strtoupper(base_convert((string) self::$countryCodeCounter++, 10, 36));
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
            'status' => 'pending', 'price_quoted' => 300, 'payment_status' => 'pending', 'payment_method' => 'online',
        ]);

        return [$booking->fresh(), $zone, $franchise];
    }

    private function makeProvider(Franchise $franchise, Zone $zone, float $lat, float $lng = 1.0): Provider
    {
        $categoryId = Service::first()->category_id;

        $providerUser = User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Provider '.Str::random(4), 'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'provider', 'status' => 'active', 'franchise_id' => $franchise->id,
        ]);

        return Provider::create([
            'user_id' => $providerUser->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'provider_type' => 'independent', 'kyc_status' => 'approved', 'is_active' => true, 'is_online' => true,
            'current_lat' => $lat, 'current_lng' => $lng, 'location_updated_at' => now(), 'skills' => [$categoryId],
        ]);
    }

    /** @return list<Provider> nearest first, each ~0.1 km further out */
    private function makeProviders(Franchise $franchise, Zone $zone, int $count): array
    {
        $providers = [];
        for ($i = 1; $i <= $count; $i++) {
            $providers[] = $this->makeProvider($franchise, $zone, 1.0 + $i * 0.001);
        }

        return $providers;
    }

    /**
     * Run one dispatch round the way the self-requeued job does, after the
     * previous round's offer window has expired unanswered.
     *
     * @return list<int> provider ids offered in this round
     */
    private function runRound(Booking $booking, int $round): array
    {
        DispatchAttempt::where('booking_id', $booking->id)->where('status', 'notified')
            ->update(['notified_at' => now()->subSeconds(120)]);

        $before = DispatchAttempt::where('booking_id', $booking->id)->max('id') ?? 0;

        (new ServiceMatchingJob($booking->id, $round))->handle(app(DispatchService::class));

        return DispatchAttempt::where('booking_id', $booking->id)->where('id', '>', $before)
            ->orderBy('id')->pluck('provider_id')->all();
    }

    // -----------------------------------------------------------------
    // A. Offer window (ring duration) and the 10-minute search window
    // -----------------------------------------------------------------

    public function test_offer_window_defaults_to_25_seconds(): void
    {
        Queue::fake();
        [$booking, $zone, $franchise] = $this->makeBookingContext();
        [$a, $b] = $this->makeProviders($franchise, $zone, 2);

        (new ServiceMatchingJob($booking->id, 1))->handle(app(DispatchService::class));

        // One offer 24s old (still inside a 25s window), one 26s old (outside).
        DispatchAttempt::where('provider_id', $a->id)->update(['notified_at' => now()->subSeconds(24)]);
        DispatchAttempt::where('provider_id', $b->id)->update(['notified_at' => now()->subSeconds(26)]);

        (new ServiceMatchingJob($booking->id, 2))->handle(app(DispatchService::class));

        $this->assertDatabaseHas('dispatch_attempts', ['provider_id' => $a->id, 'status' => 'notified']);
        $this->assertDatabaseHas('dispatch_attempts', ['provider_id' => $b->id, 'status' => 'timeout']);
        $this->assertSame('25', (new SettingsManage)->dispatchOfferTimeoutSeconds, 'Settings screen default must match the job default.');
    }

    public function test_default_search_window_is_24_rounds_or_10_minutes(): void
    {
        Queue::fake();
        [$booking, $zone, $franchise] = $this->makeBookingContext();
        $this->makeProviders($franchise, $zone, 1);

        // Round 24 is the last round that still sends offers...
        $this->assertNotEmpty($this->runRound($booking, 24));
        Queue::assertPushed(ServiceMatchingJob::class, fn ($job) => $job->round === 25);

        // ...round 25 (arriving at 24 × 25s = 600s) stops without offering or requeuing.
        Queue::fake();
        $this->assertSame([], $this->runRound($booking, 25));
        Queue::assertNotPushed(ServiceMatchingJob::class);

        $this->assertSame(600, 24 * 25);
        $this->assertSame('24', (new SettingsManage)->dispatchMaxRounds, 'Settings screen default must match the job default.');
    }

    public function test_settings_screen_accepts_a_round_count_above_the_old_20_cap(): void
    {
        $admin = User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Super Admin', 'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'super_admin', 'status' => 'active',
        ]);

        Livewire::actingAs($admin)->test(SettingsManage::class)
            ->set('dispatchMaxRounds', '24')
            ->call('saveDispatch')
            ->assertHasNoErrors();

        $this->assertSame(24, (int) Setting::get('dispatch.max_rounds', 0, []));
    }

    // -----------------------------------------------------------------
    // B. Round offset: providers beyond the old ~10 ceiling get offers
    // -----------------------------------------------------------------

    public function test_later_rounds_reach_providers_beyond_the_old_ten_provider_ceiling(): void
    {
        Queue::fake();
        [$booking, $zone, $franchise] = $this->makeBookingContext();
        $providers = $this->makeProviders($franchise, $zone, 15);
        $ids = array_map(fn (Provider $p) => $p->id, $providers);

        $round1 = $this->runRound($booking, 1);
        $round2 = $this->runRound($booking, 2);
        $round3 = $this->runRound($booking, 3);

        // Each round takes the next five by distance instead of repeating the nearest five.
        $this->assertSame(array_slice($ids, 0, 5), $round1);
        $this->assertSame(array_slice($ids, 5, 5), $round2);
        $this->assertSame(array_slice($ids, 10, 5), $round3);

        // All 15 offered within 3 rounds (75s). Before the fix only 10 were ever offered.
        $offered = DispatchAttempt::where('booking_id', $booking->id)->distinct()->pluck('provider_id');
        $this->assertCount(15, $offered);
    }

    public function test_once_everyone_has_been_offered_rounds_cycle_back_in_rank_order(): void
    {
        Queue::fake();
        [$booking, $zone, $franchise] = $this->makeBookingContext();
        $ids = array_map(fn (Provider $p) => $p->id, $this->makeProviders($franchise, $zone, 7));

        $this->assertSame(array_slice($ids, 0, 5), $this->runRound($booking, 1));
        // Two never-offered providers first, then the nearest already-offered ones.
        $this->assertSame([$ids[5], $ids[6], $ids[0], $ids[1], $ids[2]], $this->runRound($booking, 2));
    }

    public function test_circuit_breaker_still_retires_a_provider_after_three_misses(): void
    {
        Queue::fake();
        [$booking, $zone, $franchise] = $this->makeBookingContext();
        [$only] = $this->makeProviders($franchise, $zone, 1);

        // Sole provider: offered rounds 1-3, then excluded (3 timeouts) from round 4 on.
        foreach ([1, 2, 3] as $round) {
            $this->assertSame([$only->id], $this->runRound($booking, $round));
        }
        $this->assertSame([], $this->runRound($booking, 4));
        $this->assertSame(3, DispatchAttempt::where('provider_id', $only->id)->where('status', 'timeout')->count());
    }
}
