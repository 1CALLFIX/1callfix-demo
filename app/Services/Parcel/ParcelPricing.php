<?php

namespace App\Services\Parcel;

use App\Exceptions\ParcelQuoteException;
use App\Services\DispatchService;

/**
 * The one parcel price calculation (owner decision 2026-10-23): base fare covering the first included km,
 * a per-km rate after that, a per-kg rate above the free weight, and a surcharge per package size. Every
 * number is an admin setting (ParcelSettings); a missing required one refuses the quote rather than
 * guessing. Used by the customer web page, the customer API and CreateParcelOrderAction, so the price a
 * customer is shown is the price they are charged.
 */
class ParcelPricing
{
    public function __construct(private DispatchService $dispatch)
    {
    }

    /**
     * @return array{total: float, distance_km: float, lines: array<int, array{label: string, amount: float}>}
     *
     * @throws ParcelQuoteException when pricing is not configured or the parcel is outside the limits
     */
    public function quote(float $pickupLat, float $pickupLng, float $dropLat, float $dropLng, ?float $weightKg, string $size, array $scope, bool $scheduled = false): array
    {
        if (ParcelSettings::missingRequired($scope) !== []) {
            throw new ParcelQuoteException('Parcel delivery is not available here yet.');
        }
        // Stage 1: a scheduled parcel has its own base fare (parcel.schedule_base_fare); unset = no scheduling here.
        if ($scheduled && ! ParcelSettings::scheduleAvailable($scope)) {
            throw new ParcelQuoteException('Scheduled delivery is not available here. Choose "Now".');
        }
        if (! array_key_exists($size, ParcelSettings::SIZES)) {
            throw new ParcelQuoteException('Choose a package size.');
        }

        $straight = $this->dispatch->haversineKm($pickupLat, $pickupLng, $dropLat, $dropLng);
        $distance = round($straight * (float) ParcelSettings::get('parcel.road_distance_factor', $scope), 2);

        $maxDistance = (float) ParcelSettings::get('parcel.max_distance_km', $scope);
        if ($distance > $maxDistance) {
            throw new ParcelQuoteException("That drop point is {$distance} km away. We deliver parcels up to {$maxDistance} km.");
        }

        $weight = max(0.0, (float) ($weightKg ?? 0));
        $maxWeight = (float) ParcelSettings::get('parcel.max_weight_kg', $scope);
        if ($weight > $maxWeight) {
            throw new ParcelQuoteException("We accept parcels up to {$maxWeight} kg.");
        }

        $baseKm = (float) ParcelSettings::get('parcel.base_km', $scope);
        $extraKm = max(0.0, $distance - $baseKm);
        $extraKg = max(0.0, $weight - (float) ParcelSettings::get('parcel.free_weight_kg', $scope));

        $baseLabel = $scheduled ? 'Base fare, scheduled' : 'Base fare';
        $lines = [
            ['label' => $baseKm > 0 ? "{$baseLabel} (first {$this->num($baseKm)} km)" : $baseLabel, 'amount' => (float) ParcelSettings::get($scheduled ? 'parcel.schedule_base_fare' : 'parcel.base_fare', $scope)],
            ['label' => "Distance ({$this->num(round($extraKm, 2))} km)", 'amount' => round($extraKm * (float) ParcelSettings::get('parcel.per_km_rate', $scope), 2)],
            ['label' => "Weight ({$this->num(round($extraKg, 2))} kg over free limit)", 'amount' => round($extraKg * (float) ParcelSettings::get('parcel.per_kg_rate', $scope), 2)],
            ['label' => ParcelSettings::SIZES[$size].' package', 'amount' => (float) ParcelSettings::get("parcel.size_surcharge_{$size}", $scope)],
        ];
        // The base fare always shows; a zero distance/weight/size line is noise.
        $lines = array_values(array_filter($lines, fn ($line, $i) => $i === 0 || $line['amount'] > 0, ARRAY_FILTER_USE_BOTH));

        return [
            'total' => round(array_sum(array_column($lines, 'amount')), 2),
            'distance_km' => $distance,
            'lines' => $lines,
        ];
    }

    private function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
