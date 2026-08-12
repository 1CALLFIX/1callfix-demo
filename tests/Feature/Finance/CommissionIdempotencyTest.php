<?php

namespace Tests\Feature\Finance;

use App\Models\Address;
use App\Models\Booking;
use App\Models\City;
use App\Models\Country;
use App\Models\Franchise;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Models\Zone;
use App\Services\CommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Priority 2 required test area: commission idempotency. Also verifies the
 * new commissions.booking_id unique constraint (2026_08_12_001000) doesn't
 * break the documented "safe to call more than once" contract —
 * CommissionService::applyForBooking() short-circuits on an existing row
 * BEFORE the insert, so the constraint is a backstop that a normal repeat
 * call never actually reaches.
 */
class CommissionIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * countries.code is string(2) — Str::random(1) (62 possibilities) was
     * flaky under a suite that creates many countries per run. A
     * process-lifetime counter over base-36 guarantees uniqueness without
     * relying on randomness.
     */
    private static int $countryCodeCounter = 0;

    private function makeCompletedBooking(): Booking
    {
        $code = strtoupper(str_pad(base_convert((string) self::$countryCodeCounter++, 10, 36), 2, '0', STR_PAD_LEFT));

        $country = Country::create([
            'name' => 'Testland', 'code' => $code,
            'currency_code' => 'INR', 'default_timezone' => 'Asia/Kolkata', 'is_active' => true,
        ]);
        $city = City::create(['country_id' => $country->id, 'name' => 'Test City '.Str::random(6), 'is_active' => true]);
        $franchise = Franchise::create([
            'name' => 'Commission Franchise', 'slug' => Str::slug('commission-franchise-'.Str::random(8)),
            'city' => $city->name, 'country_id' => $country->id, 'city_id' => $city->id,
            'commission_model' => 'revenue_share',
            'commission_value' => 10, 'platform_fee_percent' => 5, 'status' => 'active',
        ]);
        $zone = Zone::create([
            'franchise_id' => $franchise->id, 'name' => 'Zone',
            'boundary_polygon' => [['lat' => 1, 'lng' => 1], ['lat' => 2, 'lng' => 2], ['lat' => 3, 'lng' => 3]],
            'is_active' => true,
        ]);
        $customer = User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Customer', 'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'customer', 'status' => 'active',
        ]);
        $providerUser = User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Provider', 'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'provider', 'status' => 'active', 'franchise_id' => $franchise->id,
        ]);
        $provider = Provider::create([
            'user_id' => $providerUser->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'provider_type' => 'independent', 'kyc_status' => 'approved', 'is_active' => true,
        ]);
        $address = Address::create([
            'user_id' => $customer->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'label' => 'Home', 'lat' => 1.0, 'lng' => 1.0, 'address_line' => 'Test Address',
        ]);
        $category = ServiceCategory::create([
            'module' => 'service', 'name' => 'Cat', 'slug' => 'cat-'.Str::random(6),
            'image' => 'categories/x.png', 'sort_order' => 1, 'is_active' => true,
        ]);
        $service = Service::create([
            'category_id' => $category->id, 'name' => 'Svc', 'slug' => 'svc-'.Str::random(6),
            'base_price' => 1000, 'price_type' => 'fixed', 'duration_estimate_mins' => 60,
            'is_active' => true, 'location_required' => true, 'age_restriction' => false, 'sort_order' => 1,
        ]);

        return Booking::create([
            'code' => 'TST-'.now()->format('dm').'-'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'customer_id' => $customer->id, 'provider_id' => $provider->id,
            'service_id' => $service->id, 'address_id' => $address->id,
            'status' => 'completed', 'price_quoted' => 1000, 'price_final' => 1000,
            'payment_status' => 'paid', 'payment_method' => 'online', 'completed_at' => now(),
        ]);
    }

    public function test_calling_apply_for_booking_twice_does_not_double_credit_the_wallet(): void
    {
        $booking = $this->makeCompletedBooking();
        $service = app(CommissionService::class);

        $first = $service->applyForBooking($booking);
        $balanceAfterFirst = app(\App\Services\WalletService::class)->balance($booking->provider->user);

        $second = $service->applyForBooking($booking->fresh());
        $balanceAfterSecond = app(\App\Services\WalletService::class)->balance($booking->provider->user);

        $this->assertSame($first->id, $second->id, 'A repeat call must return the SAME commission row, not create a new one.');
        $this->assertSame(1, \App\Models\Commission::where('booking_id', $booking->id)->count());
        $this->assertEquals($balanceAfterFirst, $balanceAfterSecond, 'The provider wallet must not be credited twice.');
    }

    public function test_commission_split_matches_franchise_configured_rates(): void
    {
        $booking = $this->makeCompletedBooking();
        $commission = app(CommissionService::class)->applyForBooking($booking);

        // 5% platform fee, 10% franchise revenue share, remainder to provider — on a 1000 booking.
        $this->assertEquals(50.0, $commission->platform_commission);
        $this->assertEquals(100.0, $commission->franchise_commission);
        $this->assertEquals(850.0, $commission->provider_commission);
    }

    public function test_db_level_unique_constraint_rejects_a_direct_duplicate_insert(): void
    {
        // Bypasses CommissionService entirely to prove the DB constraint
        // itself (not just the application-level check) is the backstop.
        $booking = $this->makeCompletedBooking();
        \App\Models\Commission::create(['booking_id' => $booking->id, 'provider_commission' => 1, 'franchise_commission' => 1, 'platform_commission' => 1]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        \App\Models\Commission::create(['booking_id' => $booking->id, 'provider_commission' => 2, 'franchise_commission' => 2, 'platform_commission' => 2]);
    }
}
