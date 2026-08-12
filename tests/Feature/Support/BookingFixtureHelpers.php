<?php

namespace Tests\Feature\Support;

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
use Illuminate\Support\Str;

/**
 * Shared full-stack booking fixture builder — franchise/zone/category/
 * service/customer/address/provider, and an assignable Booking with real
 * OTPs. Consolidates what CommissionIdempotencyTest, ServiceMatchingJobRaceTest,
 * and AssignBookingToWorkerActionTest each built independently, for tests
 * added from this point on.
 */
trait BookingFixtureHelpers
{
    private static int $bookingFixtureCountryCounter = 0;

    protected function makeFranchiseTree(): array
    {
        $code = strtoupper(str_pad(base_convert((string) self::$bookingFixtureCountryCounter++, 10, 36), 2, '0', STR_PAD_LEFT));
        $country = Country::create(['name' => 'Testland', 'code' => $code, 'currency_code' => 'INR', 'default_timezone' => 'Asia/Kolkata', 'is_active' => true]);
        $city = City::create(['country_id' => $country->id, 'name' => 'City '.Str::random(6), 'is_active' => true]);
        $franchise = Franchise::create([
            'name' => 'Franchise', 'slug' => Str::slug('franchise-'.Str::random(8)),
            'city' => $city->name, 'country_id' => $country->id, 'city_id' => $city->id,
            'commission_model' => 'revenue_share', 'commission_value' => 10, 'platform_fee_percent' => 5,
            'status' => 'active',
        ]);
        $zone = Zone::create([
            'franchise_id' => $franchise->id, 'name' => 'Zone',
            'boundary_polygon' => [['lat' => 1, 'lng' => 1], ['lat' => 2, 'lng' => 2], ['lat' => 3, 'lng' => 3]],
            'is_active' => true, 'default_dispatch_radius_km' => 8,
        ]);

        return [$country, $city, $franchise, $zone];
    }

    protected function makeCustomer(): User
    {
        return User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Customer', 'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'customer', 'status' => 'active',
        ]);
    }

    protected function makeAddress(User $customer, Franchise $franchise, Zone $zone): Address
    {
        return Address::create([
            'user_id' => $customer->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'label' => 'Home', 'lat' => 1.0, 'lng' => 1.0, 'address_line' => 'Addr',
        ]);
    }

    protected function makeCategoryAndService(): array
    {
        $category = ServiceCategory::create([
            'module' => 'service', 'name' => 'Cat', 'slug' => 'cat-'.Str::random(6),
            'image' => 'categories/x.png', 'sort_order' => 1, 'is_active' => true,
        ]);
        $service = Service::create([
            'category_id' => $category->id, 'name' => 'Svc', 'slug' => 'svc-'.Str::random(6),
            'base_price' => 500, 'price_type' => 'fixed', 'duration_estimate_mins' => 60,
            'is_active' => true, 'location_required' => true, 'age_restriction' => false, 'sort_order' => 1,
        ]);

        return [$category, $service];
    }

    protected function makeProviderIn(Franchise $franchise, Zone $zone): Provider
    {
        $user = User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Provider', 'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'provider', 'status' => 'active', 'franchise_id' => $franchise->id,
        ]);

        return Provider::create([
            'user_id' => $user->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'provider_type' => 'independent', 'kyc_status' => 'approved', 'is_active' => true, 'is_online' => true,
        ]);
    }

    /**
     * A complete, ready-to-accept-or-assign scenario: franchise/zone,
     * customer+address, category+service, one provider, and a
     * 'searching_provider' booking. Returns everything by key so a caller
     * only needs what it actually uses.
     */
    protected function makeBookingScenario(string $status = 'searching_provider'): array
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        [$category, $service] = $this->makeCategoryAndService();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        $provider = $this->makeProviderIn($franchise, $zone);

        $booking = Booking::create([
            'code' => 'TST-'.now()->format('dm').'-'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'customer_id' => $customer->id, 'service_id' => $service->id, 'address_id' => $address->id,
            'status' => $status, 'price_quoted' => 500, 'payment_status' => 'pending', 'payment_method' => 'online',
        ]);

        return compact('country', 'city', 'franchise', 'zone', 'category', 'service', 'customer', 'address', 'provider', 'booking');
    }

    /**
     * Same scenario, already accepted by the provider (status=assigned,
     * provider_id set, real start_otp/completion_otp generated) — for tests
     * that need to start/complete/reassign/cancel from that point onward.
     */
    protected function makeAssignedBookingScenario(): array
    {
        $scenario = $this->makeBookingScenario('assigned');
        $scenario['booking']->update([
            'provider_id' => $scenario['provider']->id,
            'start_otp' => '1234',
            'completion_otp' => '5678',
        ]);
        $scenario['booking'] = $scenario['booking']->fresh();

        return $scenario;
    }
}
