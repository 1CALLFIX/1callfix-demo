<?php

namespace App\Services\Parcel;

use App\Models\FieldWorker;
use App\Models\Franchise;
use App\Models\Module;
use App\Support\Modules;

/**
 * Owner rule 2026-10-24: riders deliver parcels, so riders are onboarded BEFORE parcel is switched on. The
 * Modules screen refuses to activate Parcel Delivery for a location until this reports no problems:
 *  - at least one active, KYC-approved parcel rider (account not suspended) inside that location;
 *  - every required price set (ParcelSettings) — otherwise nothing could be booked anyway.
 * Deactivating is never blocked.
 */
class ParcelReadiness
{
    /** Riders who could take a parcel in this location (online or not). */
    public function riderCount(string $scopeLevel, int $scopeId): int
    {
        return FieldWorker::query()
            ->where('is_active', true)
            ->where('kyc_status', 'approved')
            ->whereHas('capabilities', fn ($q) => $q->where('capability_type', 'parcel_rider'))
            ->whereHas('user', fn ($q) => $q->where('status', '!=', 'suspended'))
            ->when($scopeLevel === 'zone', fn ($q) => $q->where('zone_id', $scopeId))
            ->when($scopeLevel === 'franchise', fn ($q) => $q->where('franchise_id', $scopeId))
            ->when($scopeLevel === 'city', fn ($q) => $q->whereHas('franchise', fn ($f) => $f->where('city_id', $scopeId)))
            ->when($scopeLevel === 'country', fn ($q) => $q->whereHas('franchise', fn ($f) => $f->where('country_id', $scopeId)))
            ->count();
    }

    /**
     * Whether /partners takes delivery-rider sign-ups (owner rule 2026-10-25: every partner joins through /partners).
     * Riders are signed up BEFORE parcel opens to customers, so this does not wait for the module to be switched on:
     * it is true once parcel is a built module and its prices are complete for at least one franchise.
     */
    public function recruitingRiders(): bool
    {
        if (! Module::where('code', Modules::PARCEL)->where('is_implemented', true)->exists()) {
            return false;
        }

        foreach (Franchise::query()->get(['id', 'city_id', 'country_id']) as $franchise) {
            $scope = array_filter(['franchise_id' => $franchise->id, 'city_id' => $franchise->city_id, 'country_id' => $franchise->country_id]);
            if (ParcelSettings::missingRequired($scope) === []) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> what still has to be done before parcel can be activated here (empty = ready) */
    public function problems(string $scopeLevel, int $scopeId, array $resolvedScope): array
    {
        $problems = [];

        if ($this->riderCount($scopeLevel, $scopeId) === 0) {
            $problems[] = 'No parcel rider is approved here yet. Riders apply on the partner page (/partners); approve them in Partners → People → Review.';
        }

        $missing = ParcelSettings::missingRequired($resolvedScope);
        if ($missing !== []) {
            $problems[] = 'Set the parcel prices first in Parcel Settings: '
                .implode(', ', array_map(fn ($key) => ParcelSettings::REGISTRY[$key]['label'], $missing)).'.';
        }

        return $problems;
    }
}
