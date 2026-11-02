<?php

namespace App\Actions;

use App\Exceptions\AccountAlreadyExistsException;
use App\Models\Provider;
use App\Models\Store;
use App\Models\User;
use App\Models\Zone;
use App\Services\ActivityLogger;
use App\Support\Modules;
use Illuminate\Support\Facades\DB;

/**
 * E-commerce Phase 1 — a seller joins through /partners → the one partner sign-up (OTP, documents, admin approval;
 * owner rule 2026-10-25). A seller is the existing Provider account tier (Store::provider_id, Phase 24) plus one
 * Store that waits at approval_status 'pending', switched off and closed, until an admin approves it in
 * Admin → Stores. Documents go on the Provider through the usual KYC queue.
 *
 * Three starting points, one result: a new number (new account + provider), a customer account (provider profile
 * added, D10), or an account that is already a service partner (its provider gets the store; no second profile).
 */
class RegisterSellerAction
{
    public function __construct(private RegisterProviderAction $providers)
    {
    }

    /**
     * @param  array{address?: string|null, lat?: float|string|null, lng?: float|string|null}  $registration
     *
     * @throws AccountAlreadyExistsException when the number is taken by an account that cannot open a shop
     */
    public function execute(
        Zone $zone,
        string $storeName,
        ?User $existing,
        array $registration,
        string $name = '',
        string $nationalPhone = '',
        ?string $email = null,
        string $plainPassword = '',
        string $module = Modules::COMMERCE,
        ?string $fssaiNumber = null,
    ): Store {
        // Food F5: a restaurant joins the same way; it is a Store with module 'food' and its FSSAI licence number.
        $module = $module === Modules::FOOD ? Modules::FOOD : Modules::COMMERCE;

        return DB::transaction(function () use ($zone, $storeName, $existing, $registration, $name, $nationalPhone, $email, $plainPassword, $module, $fssaiNumber) {
            if ($existing !== null) {
                $existing = User::whereKey($existing->id)->lockForUpdate()->first();
                if (! $existing?->canApplyAsPartner($module)) {
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

            $store = Store::create([
                'provider_id' => $provider->id,
                'franchise_id' => $zone->franchise_id,
                'zone_id' => $zone->id,
                'module' => $module,
                'fssai_number' => $module === Modules::FOOD && filled($fssaiNumber) ? $fssaiNumber : null,
                'name' => $storeName,
                'address_line' => (string) ($registration['address'] ?? ''),
                // No pin: the zone centre holds the place until the seller pins the shop in the seller portal
                // (riders are matched by distance from here).
                'lat' => $registration['lat'] ?? $zone->center_lat ?? 0,
                'lng' => $registration['lng'] ?? $zone->center_lng ?? 0,
                'phone' => $provider->user?->phone,
                'is_active' => false,
                'is_open' => false,
                'approval_status' => 'pending',
            ]);

            ActivityLogger::logModel($provider->user, $store, ($module === Modules::FOOD ? 'Restaurant' : 'Seller').' applied (partner sign-up); awaiting approval', array_filter([
                'store' => $storeName,
                'address' => $registration['address'] ?? null,
                'out_of_coverage' => ($registration['out_of_coverage'] ?? false) ?: null,
            ]));

            return $store;
        });
    }
}
