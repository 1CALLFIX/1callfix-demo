<?php

namespace App\Actions;

use App\Exceptions\AccountAlreadyExistsException;
use App\Models\Hall;
use App\Models\Provider;
use App\Models\User;
use App\Models\Zone;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Function halls R1 (owner 2026-11-13) — a hall owner joins through /partners → the one partner sign-up (OTP,
 * documents, admin approval), exactly like a hotel or an event organiser. Result: the existing Provider account tier plus
 * one Hall as a draft, switched off. The owner adds slots, prices and photos in the hall portal and sends it for review.
 *
 * Starting points: a new number (new account + provider), a customer account (provider profile added), or an account
 * that is already a partner, e.g. a hotel that also has a banquet hall (its provider gets the hall).
 */
class RegisterHallAction
{
    public function __construct(private RegisterProviderAction $providers)
    {
    }

    /**
     * @param  array{address?: string|null, lat?: float|string|null, lng?: float|string|null, out_of_coverage?: bool}  $registration
     *
     * @throws AccountAlreadyExistsException when the number belongs to an account that cannot add a hall
     */
    public function execute(
        Zone $zone,
        string $hallName,
        ?User $existing,
        array $registration,
        string $name = '',
        string $nationalPhone = '',
        ?string $email = null,
        ?string $plainPassword = null,
    ): Hall {
        return DB::transaction(function () use ($zone, $hallName, $existing, $registration, $name, $nationalPhone, $email, $plainPassword) {
            if ($existing !== null) {
                $existing = User::whereKey($existing->id)->lockForUpdate()->first();
                if (! $existing?->canApplyAsPartner('halls')) {
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
            $hall = Hall::create([
                'provider_id' => $provider->id,
                'franchise_id' => $zone->franchise_id,
                'zone_id' => $zone->id,
                'name' => $hallName,
                'slug' => Hall::uniqueSlug($hallName),
                'address_line' => (string) ($registration['address'] ?? ''),
                'lat' => is_numeric($lat) ? (float) $lat : null,
                'lng' => is_numeric($lng) ? (float) $lng : null,
                'contact_phone' => $provider->user?->phone,
                'status' => 'draft',
                'is_active' => false,
            ]);

            ActivityLogger::logModel($provider->user, $hall, 'Function hall applied (partner sign-up); draft until sent for review', array_filter([
                'hall' => $hallName,
                'address' => $registration['address'] ?? null,
                'out_of_coverage' => ($registration['out_of_coverage'] ?? false) ?: null,
            ]));

            return $hall;
        });
    }
}
