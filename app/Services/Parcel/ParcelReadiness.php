<?php

namespace App\Services\Parcel;

use App\Models\FieldWorker;

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

    /** @return array<int, string> what still has to be done before parcel can be activated here (empty = ready) */
    public function problems(string $scopeLevel, int $scopeId, array $resolvedScope): array
    {
        $problems = [];

        if ($this->riderCount($scopeLevel, $scopeId) === 0) {
            $problems[] = 'No parcel rider is onboarded here yet — add one in Workers → Add parcel rider.';
        }

        $missing = ParcelSettings::missingRequired($resolvedScope);
        if ($missing !== []) {
            $problems[] = 'Set the parcel prices first in Parcel Settings: '
                .implode(', ', array_map(fn ($key) => ParcelSettings::REGISTRY[$key]['label'], $missing)).'.';
        }

        return $problems;
    }
}
