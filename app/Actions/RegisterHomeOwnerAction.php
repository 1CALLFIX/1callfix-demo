<?php

namespace App\Actions;

use App\Exceptions\AccountAlreadyExistsException;
use App\Models\HouseListing;
use App\Models\Provider;
use App\Models\User;
use App\Models\Zone;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Houses for rent (owner 2026-11-14) — a house owner joins through /partners → the one partner sign-up (mobile code,
 * documents, admin approval), like a hall owner. Result: the existing Provider account tier plus one HouseListing as a
 * draft for the address given. The owner taps rent, size, facilities and adds photos in the Rental portal, then sends
 * it for review. Starting points: a new number, a customer account, or an account that is already a partner (a hall
 * owner who also lets a house).
 */
class RegisterHomeOwnerAction
{
    public function __construct(private RegisterProviderAction $providers)
    {
    }

    /**
     * @param  array{address?: string|null, lat?: float|string|null, lng?: float|string|null, out_of_coverage?: bool}  $registration
     *
     * @throws AccountAlreadyExistsException when the number belongs to an account that cannot add a home
     */
    public function execute(
        Zone $zone,
        ?User $existing,
        array $registration,
        string $name = '',
        string $nationalPhone = '',
        ?string $email = null,
        ?string $plainPassword = null,
    ): HouseListing {
        return DB::transaction(function () use ($zone, $existing, $registration, $name, $nationalPhone, $email, $plainPassword) {
            if ($existing !== null) {
                $existing = User::whereKey($existing->id)->lockForUpdate()->first();
                if (! $existing?->canApplyAsPartner('homes')) {
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
            $title = HouseListing::makeTitle(null, null, $zone->name);
            $listing = HouseListing::create([
                'provider_id' => $provider->id,
                'franchise_id' => $zone->franchise_id,
                'zone_id' => $zone->id,
                'title' => $title,
                'slug' => HouseListing::uniqueSlug($title),
                'address_line' => (string) ($registration['address'] ?? ''),
                'lat' => is_numeric($lat) ? (float) $lat : null,
                'lng' => is_numeric($lng) ? (float) $lng : null,
                'contact_phone' => $provider->user?->phone,
                'status' => 'draft',
                'is_active' => false,
            ]);

            ActivityLogger::logModel($provider->user, $listing, 'House owner applied (partner sign-up); home is a draft until sent for review', array_filter([
                'address' => $registration['address'] ?? null,
                'out_of_coverage' => ($registration['out_of_coverage'] ?? false) ?: null,
            ]));

            return $listing;
        });
    }
}
