<?php

namespace App\Actions;

use App\Exceptions\AccountAlreadyExistsException;
use App\Models\Provider;
use App\Models\User;
use App\Models\VehicleListing;
use App\Models\Zone;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Vehicles for rent (owner 2026-11-18, OLX-style) — a vehicle owner joins through /partners ("Vehicle owner") → the one
 * partner sign-up (mobile code, documents, admin approval), like a property owner. Result: the existing Provider account
 * tier plus one VehicleListing as a draft in the owner's area. The owner taps the kind, make, price and photos in the
 * Rental portal, then sends it for review. Starting points: a new number, a customer account, or an account that is
 * already a partner (a property owner who also rents out a car).
 */
class RegisterVehicleOwnerAction
{
    public function __construct(private RegisterProviderAction $providers)
    {
    }

    /**
     * @param  array{address?: string|null, lat?: float|string|null, lng?: float|string|null, out_of_coverage?: bool}  $registration
     *
     * @throws AccountAlreadyExistsException when the number belongs to an account that cannot add a vehicle
     */
    public function execute(
        Zone $zone,
        ?User $existing,
        array $registration,
        string $name = '',
        string $nationalPhone = '',
        ?string $email = null,
        ?string $plainPassword = null,
    ): VehicleListing {
        return DB::transaction(function () use ($zone, $existing, $registration, $name, $nationalPhone, $email, $plainPassword) {
            if ($existing !== null) {
                $existing = User::whereKey($existing->id)->lockForUpdate()->first();
                if (! $existing?->canApplyAsPartner('vehicles')) {
                    throw new AccountAlreadyExistsException('mobile number');
                }
                $provider = Provider::where('user_id', $existing->id)->first()
                    ?? $this->providers->forExistingCustomer($existing, $zone->franchise_id, $zone->id, $registration);
            } else {
                $provider = $this->providers->execute(
                    name: $name,
                    phone: $nationalPhone,
                    franchiseId: $zone->franchise_id,
                    zoneId: $zone->id,
                    plainPassword: filled($plainPassword) ? $plainPassword : null,
                    email: $email,
                    phoneVerified: true,
                    registration: $registration,
                );
            }

            $lat = $registration['lat'] ?? null;
            $lng = $registration['lng'] ?? null;
            $title = VehicleListing::makeTitle(null, null, null, $zone->name);
            $listing = VehicleListing::create([
                'provider_id' => $provider->id,
                'franchise_id' => $zone->franchise_id,
                'zone_id' => $zone->id,
                'title' => $title,
                'slug' => VehicleListing::uniqueSlug($title),
                'address_line' => (string) ($registration['address'] ?? ''),
                'lat' => is_numeric($lat) ? (float) $lat : null,
                'lng' => is_numeric($lng) ? (float) $lng : null,
                'contact_phone' => $provider->user?->phone,
                'status' => 'draft',
                'is_active' => false,
            ]);

            ActivityLogger::logModel($provider->user, $listing, 'Vehicle owner applied (partner sign-up); vehicle is a draft until sent for review', array_filter([
                'address' => $registration['address'] ?? null,
                'out_of_coverage' => ($registration['out_of_coverage'] ?? false) ?: null,
            ]));

            return $listing;
        });
    }
}
