<?php

namespace App\Actions;

use App\Exceptions\AccountAlreadyExistsException;
use App\Models\EventOrganiser;
use App\Models\Provider;
use App\Models\User;
use App\Models\Zone;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Events E1 (owner 2026-11-13) — an event organiser joins through /partners → the one partner sign-up (OTP, documents,
 * admin approval), exactly like a hotel (RegisterHotelAction). Result: the existing Provider account tier plus one
 * EventOrganiser that waits at approval_status 'pending', switched off, until an admin approves it in Admin → Events.
 *
 * Starting points: a new number (new account + provider), a customer account (provider profile added), or an account
 * that is already a partner, e.g. a hotel that also runs a New Year party (its provider gets the organiser).
 */
class RegisterOrganiserAction
{
    public function __construct(private RegisterProviderAction $providers)
    {
    }

    /**
     * @param  array{address?: string|null, lat?: float|string|null, lng?: float|string|null, out_of_coverage?: bool}  $registration
     *
     * @throws AccountAlreadyExistsException when the number belongs to an account that cannot add an organiser
     */
    public function execute(
        Zone $zone,
        string $organiserName,
        ?User $existing,
        array $registration,
        string $name = '',
        string $nationalPhone = '',
        ?string $email = null,
        ?string $plainPassword = null,
    ): EventOrganiser {
        return DB::transaction(function () use ($zone, $organiserName, $existing, $registration, $name, $nationalPhone, $email, $plainPassword) {
            if ($existing !== null) {
                $existing = User::whereKey($existing->id)->lockForUpdate()->first();
                if (! $existing?->canApplyAsPartner('events')) {
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

            $organiser = EventOrganiser::create([
                'provider_id' => $provider->id,
                'franchise_id' => $zone->franchise_id,
                'zone_id' => $zone->id,
                'name' => $organiserName,
                'address_line' => (string) ($registration['address'] ?? ''),
                'contact_phone' => $provider->user?->phone,
                'is_active' => false,
                'approval_status' => 'pending',
            ]);

            ActivityLogger::logModel($provider->user, $organiser, 'Event organiser applied (partner sign-up); awaiting approval', array_filter([
                'organiser' => $organiserName,
                'address' => $registration['address'] ?? null,
                'out_of_coverage' => ($registration['out_of_coverage'] ?? false) ?: null,
            ]));

            return $organiser;
        });
    }
}
