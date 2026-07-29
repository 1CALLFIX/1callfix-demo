<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Provider;
use Illuminate\Support\Collection;

class DispatchService
{
    /**
     * Find eligible providers for a booking, ranked nearest-first.
     *
     * Eligibility:
     *   - Same zone as the booking
     *   - Online right now
     *   - KYC approved, account active
     *   - Skills include this service's category
     *   - Not currently tied up on another active booking
     *   - Not already offered this specific booking (no dispatch_attempts row yet)
     *   - Within the zone's dispatch radius (Haversine distance from customer's address)
     *
     * @return Collection<int, array{provider: Provider, distance_km: float}>
     */
    public function findCandidates(Booking $booking, int $limit = 5): Collection
    {
        $booking->loadMissing(['zone', 'address', 'service']);

        if (!$booking->zone || !$booking->address) {
            return collect();
        }

        $radiusKm = $booking->zone->default_dispatch_radius_km ?? 8;
        $categoryId = $booking->service->category_id;

        $alreadyOfferedProviderIds = $booking->dispatchAttempts()
            ->pluck('provider_id');

        $busyProviderIds = Booking::whereIn('status', ['assigned', 'provider_en_route', 'in_progress'])
            ->whereNotNull('provider_id')
            ->pluck('provider_id');

        $candidates = Provider::query()
            ->where('zone_id', $booking->zone_id)
            ->where('is_online', true)
            ->where('is_active', true)
            ->where('kyc_status', 'approved')
            ->whereNotIn('id', $alreadyOfferedProviderIds)
            ->whereNotIn('id', $busyProviderIds)
            ->whereNotNull('current_lat')
            ->whereNotNull('current_lng')
            ->get()
            ->filter(function (Provider $provider) use ($categoryId) {
                $skills = $provider->skills ?? [];
                return in_array($categoryId, $skills, strict: false);
            })
            ->map(function (Provider $provider) use ($booking) {
                return [
                    'provider' => $provider,
                    'distance_km' => $this->haversineKm(
                        (float) $booking->address->lat,
                        (float) $booking->address->lng,
                        (float) $provider->current_lat,
                        (float) $provider->current_lng,
                    ),
                ];
            })
            ->filter(fn ($c) => $c['distance_km'] <= $radiusKm)
            ->sortBy('distance_km')
            ->take($limit)
            ->values();

        return $candidates;
    }

    /**
     * Great-circle distance between two lat/lng points, in kilometers.
     */
    public function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round($earthRadiusKm * $c, 2);
    }
}
