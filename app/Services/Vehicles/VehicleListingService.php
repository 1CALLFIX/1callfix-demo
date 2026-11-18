<?php

namespace App\Services\Vehicles;

use App\Models\City;
use App\Models\User;
use App\Models\VehicleContactView;
use App\Models\VehicleListing;
use App\Support\RentalKinds;
use App\Support\VehicleListingSettings;

/**
 * Vehicles for rent (owner 2026-11-18, OLX-style) — who sees what and the owner's number. No money moves between
 * customer and owner (the platform earns the listing fee, see ListingFeeService).
 *
 * - A listing shows where Rental is on and the "Vehicles" kind is on (Rental Settings) for its area, approved, switched
 *   on and not expired.
 * - The owner's number: only signed-in customers, only when Rental Settings allows it, a daily limit of vehicles.
 */
class VehicleListingService
{
    public static function scope(VehicleListing $listing): array
    {
        $listing->loadMissing('franchise');

        return $listing->scope();
    }

    public static function kindOn(VehicleListing $listing): bool
    {
        return RentalKinds::active(RentalKinds::VEHICLES, self::scope($listing));
    }

    public static function visible(VehicleListing $listing): bool
    {
        return $listing->isLive() && self::kindOn($listing);
    }

    /** Live listings in this city (test owners hidden by the shop rule, like homes and halls). */
    public static function publicIn(City $city)
    {
        $query = VehicleListing::query()->where('status', 'approved')->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereHas('franchise', fn ($q) => $q->where('city_id', $city->id));
        if (\App\Support\MarketplaceSettings::on('marketplace.hide_test_shops') && ! \App\Models\Store::isTestViewer(auth()->user())) {
            $query->whereDoesntHave('provider.user', fn ($q) => $q->where('email', 'like', '%'.\App\Console\Commands\TestAccountsCommand::DOMAIN));
        }

        return $query;
    }

    /** May this customer see the owner's number now? 'ok' | 'off' | 'limit'. A vehicle already seen stays visible. */
    public static function phoneAccess(User $customer, VehicleListing $listing): string
    {
        $scope = self::scope($listing);
        if (! VehicleListingSettings::on('vehicles.show_owner_phone', $scope)) {
            return 'off';
        }
        if (VehicleContactView::where('customer_id', $customer->id)->where('vehicle_listing_id', $listing->id)->exists()) {
            return 'ok';
        }
        $seen = VehicleContactView::where('customer_id', $customer->id)->where('created_at', '>=', now()->subDay())->count();

        return $seen >= (int) VehicleListingSettings::get('vehicles.phone_views_per_day', $scope) ? 'limit' : 'ok';
    }

    /** @throws \RuntimeException with a customer-facing reason */
    public function revealPhone(User $customer, VehicleListing $listing): string
    {
        if (! self::visible($listing)) {
            throw new \RuntimeException('This vehicle is not available right now.');
        }
        if ($listing->provider?->user_id === $customer->id) {
            throw new \RuntimeException('This is your own vehicle.');
        }
        $access = self::phoneAccess($customer, $listing);
        if ($access !== 'ok') {
            throw new \RuntimeException($access === 'off' ? 'The owner\'s number is not shown here yet. Please contact us.' : 'You have seen many numbers today. Please try tomorrow.');
        }
        VehicleContactView::firstOrCreate(['vehicle_listing_id' => $listing->id, 'customer_id' => $customer->id]);

        return (string) ($listing->contact_phone ?: $listing->provider?->user?->phone);
    }
}
