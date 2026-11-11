<?php

namespace App\Actions;

use App\Exceptions\AccountAlreadyExistsException;
use App\Models\Accommodation;
use App\Models\AccommodationType;
use App\Models\Provider;
use App\Models\User;
use App\Models\Zone;
use App\Services\ActivityLogger;
use App\Support\HotelSettings;
use Illuminate\Support\Facades\DB;

/**
 * Hotels H1 (owner 2026-11-12) — a hotel, lodge or stay host joins through /partners → the one partner sign-up (OTP,
 * documents, admin approval), exactly like a shop (RegisterSellerAction). Result: the existing Provider account tier
 * plus one Accommodation that waits at approval_status 'pending', switched off, until an admin approves it in
 * Admin → Accommodations. Documents go on the Provider through the usual KYC queue.
 *
 * Starting points: a new number (new account + provider), a customer account (provider profile added), or an
 * account that is already a partner (its provider gets the hotel; no second profile).
 */
class RegisterHotelAction
{
    public function __construct(private RegisterProviderAction $providers)
    {
    }

    /**
     * @param  array{address?: string|null, lat?: float|string|null, lng?: float|string|null, out_of_coverage?: bool}  $registration
     *
     * @throws AccountAlreadyExistsException when the number belongs to an account that cannot add a hotel
     * @throws \RuntimeException when the chosen kind of stay is not taken (home stays switched off)
     */
    public function execute(
        Zone $zone,
        string $hotelName,
        string $typeSlug,
        ?User $existing,
        array $registration,
        string $name = '',
        string $nationalPhone = '',
        ?string $email = null,
        string $plainPassword = '',
    ): Accommodation {
        $type = self::joinableTypes()->firstWhere('slug', $typeSlug);
        if ($type === null) {
            throw new \RuntimeException('Choose the kind of stay you run.');
        }

        return DB::transaction(function () use ($zone, $hotelName, $type, $existing, $registration, $name, $nationalPhone, $email, $plainPassword) {
            if ($existing !== null) {
                $existing = User::whereKey($existing->id)->lockForUpdate()->first();
                if (! $existing?->canApplyAsPartner('hotel')) {
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
                    plainPassword: $plainPassword,
                    email: $email,
                    phoneVerified: true,
                    registration: $registration,
                );
            }

            $hotel = Accommodation::create([
                'provider_id' => $provider->id,
                'accommodation_type_id' => $type->id,
                'franchise_id' => $zone->franchise_id,
                'zone_id' => $zone->id,
                'name' => $hotelName,
                'address_line' => (string) ($registration['address'] ?? ''),
                // No pin yet: the zone centre holds the place until the hotel pins it in its portal.
                'lat' => $registration['lat'] ?? $zone->center_lat ?? 0,
                'lng' => $registration['lng'] ?? $zone->center_lng ?? 0,
                'contact_phone' => $provider->user?->phone,
                'is_active' => false,
                'approval_status' => 'pending',
            ]);

            ActivityLogger::logModel($provider->user, $hotel, 'Hotel applied (partner sign-up); awaiting approval', array_filter([
                'hotel' => $hotelName,
                'type' => $type->name,
                'address' => $registration['address'] ?? null,
                'out_of_coverage' => ($registration['out_of_coverage'] ?? false) ?: null,
            ]));

            return $hotel;
        });
    }

    /** Kinds of stay a partner can sign up as: private homes only while Hotel Settings allows home stays. */
    public static function joinableTypes(): \Illuminate\Support\Collection
    {
        return AccommodationType::query()
            ->when(! HotelSettings::homeStaysAllowed(), fn ($q) => $q->whereNotIn('slug', HotelSettings::HOME_STAY_TYPES))
            ->orderBy('id')->get(['id', 'name', 'slug']);
    }
}
