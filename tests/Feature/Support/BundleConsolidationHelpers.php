<?php

namespace Tests\Feature\Support;

use App\Models\Address;
use App\Models\Booking;
use App\Models\BookingBundle;
use App\Models\City;
use App\Models\Country;
use App\Models\DispatchAttempt;
use App\Models\Franchise;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Support\Str;

/**
 * Phase E4 fixtures — a self-contained franchise/zone/category world plus
 * skilled+located providers and schedulable bookings, shared by the
 * availability, acceptance-race and consolidation test files. Mirrors the
 * builders ServiceMatchingTimeoutReeligibilityTest wrote inline (skills +
 * current_lat/lng on the provider, real duration on the service).
 */
trait BundleConsolidationHelpers
{
    private static int $e4CountryCounter = 0;

    /** @return array{country: Country, city: City, franchise: Franchise, zone: Zone, category: ServiceCategory} */
    protected function makeWorld(): array
    {
        $code = strtoupper(str_pad(base_convert((string) self::$e4CountryCounter++, 10, 36), 2, '0', STR_PAD_LEFT));
        $country = Country::create(['name' => 'Testland', 'code' => $code, 'currency_code' => 'INR', 'default_timezone' => 'Asia/Kolkata', 'is_active' => true]);
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

        return compact('country', 'city', 'franchise', 'zone', 'category');
    }

    protected function makeService(ServiceCategory $category, int $durationMins = 60, array $overrides = []): Service
    {
        return Service::create(array_merge([
            'category_id' => $category->id, 'name' => 'Svc '.Str::random(4), 'slug' => 'svc-'.Str::random(6),
            'base_price' => 300, 'price_type' => 'fixed', 'duration_estimate_mins' => $durationMins,
            'is_active' => true, 'location_required' => true, 'age_restriction' => false, 'sort_order' => 1,
        ], $overrides));
    }

    protected function makeCustomer(): User
    {
        return User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Customer', 'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'customer', 'status' => 'active',
        ]);
    }

    protected function makeAddress(User $customer, Franchise $franchise, Zone $zone, float $lat = 1.0, float $lng = 1.0): Address
    {
        return Address::create([
            'user_id' => $customer->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'label' => 'Home', 'lat' => $lat, 'lng' => $lng, 'address_line' => 'Addr',
        ]);
    }

    protected function makeSkilledProvider(Franchise $franchise, Zone $zone, int $categoryId, float $lat = 1.0, float $lng = 1.0, array $overrides = []): Provider
    {
        $user = User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Provider '.Str::random(4), 'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'provider', 'status' => 'active', 'franchise_id' => $franchise->id,
        ]);

        return Provider::create(array_merge([
            'user_id' => $user->id, 'franchise_id' => $franchise->id, 'zone_id' => $zone->id,
            'provider_type' => 'independent', 'kyc_status' => 'approved', 'is_active' => true, 'is_online' => true,
            'current_lat' => $lat, 'current_lng' => $lng, 'skills' => [$categoryId],
        ], $overrides));
    }

    /**
     * A stand-alone booking (no bundle) with the given schedule + status.
     * $ctx is a makeWorld() array; needs 'franchise','zone','category'.
     */
    protected function makeScheduledBooking(
        array $ctx,
        Service $service,
        ?string $scheduledAt,
        string $status = 'searching_provider',
        array $overrides = [],
    ): Booking {
        $customer = $ctx['customer'] ??= $this->makeCustomer();
        $address = $ctx['address'] ??= $this->makeAddress($customer, $ctx['franchise'], $ctx['zone']);

        return Booking::create(array_merge([
            'code' => 'E4-'.now()->format('dm').'-'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'franchise_id' => $ctx['franchise']->id, 'zone_id' => $ctx['zone']->id,
            'customer_id' => $customer->id, 'service_id' => $service->id, 'address_id' => $address->id,
            'status' => $status, 'scheduled_at' => $scheduledAt,
            'price_quoted' => 300, 'payment_status' => 'pending', 'payment_method' => 'online',
        ], $overrides));
    }

    /** Open a fresh `notified` dispatch offer for ($booking, $provider). */
    protected function offer(Booking $booking, Provider $provider): DispatchAttempt
    {
        return DispatchAttempt::create([
            'booking_id' => $booking->id, 'provider_id' => $provider->id,
            'status' => 'notified', 'distance_km' => 1.0, 'notified_at' => now(),
        ]);
    }

    /** Minimal 2+ child bundle with all children `searching_provider`, unassigned. */
    protected function makeBundleWithChildren(array $ctx, array $children): array
    {
        $customer = $ctx['customer'] ??= $this->makeCustomer();
        $address = $ctx['address'] ??= $this->makeAddress($customer, $ctx['franchise'], $ctx['zone']);

        $bundle = BookingBundle::create([
            'code' => 'BND-'.Str::upper(Str::random(8)),
            'franchise_id' => $ctx['franchise']->id, 'zone_id' => $ctx['zone']->id,
            'customer_id' => $customer->id, 'address_id' => $address->id,
            'status' => 'active', 'total_price_quoted' => 0,
            'payment_status' => 'paid', 'payment_method' => 'wallet',
        ]);

        $rows = [];
        foreach ($children as $spec) {
            $rows[] = $this->makeScheduledBooking(
                $ctx,
                $spec['service'],
                $spec['scheduled_at'] ?? null,
                $spec['status'] ?? 'searching_provider',
                ['booking_bundle_id' => $bundle->id, 'address_id' => ($spec['address'] ?? $address)->id],
            );
        }

        return [$bundle, $rows];
    }
}
