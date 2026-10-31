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
     * Stage 2 (2026-10-31): $extraDrops = drop 2, 3, ... as [[lat, lng], ...] in delivery order; distance is the
     * route pickup → drop 1 → drop 2 …, each extra drop adds parcel.extra_stop_fee, and $returnTrip adds
     * parcel.return_trip_percent of the delivery price. Each unavailable option refuses the quote.
     *
     * $declaredValue = protection (Stage 2): the sender declares the parcel's value and pays parcel.protection_fee_percent
     * of it (min/max fee), up to parcel.protection_max_cover; refused where protection is off.
     *
     * @return array{total: float, distance_km: float, lines: array<int, array{label: string, amount: float}>, protection_fee: float}
     *
     * @throws ParcelQuoteException when pricing is not configured or the parcel is outside the limits
     */
    public function quote(float $pickupLat, float $pickupLng, float $dropLat, float $dropLng, ?float $weightKg, string $size, array $scope, bool $scheduled = false, array $extraDrops = [], bool $returnTrip = false, ?float $declaredValue = null): array
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

        $extraDrops = array_values($extraDrops);
        if ($extraDrops !== [] && count($extraDrops) + 1 > ParcelSettings::maxDrops($scope)) {
            $max = ParcelSettings::maxDrops($scope);
            throw new ParcelQuoteException($max > 1 ? "You can add up to {$max} drop points." : 'Only one drop point is available here.');
        }
        if ($declaredValue !== null) {
            if (! ParcelSettings::protectionAvailable($scope)) {
                throw new ParcelQuoteException('Parcel protection is not available here.');
            }
            $cover = (float) ParcelSettings::get('parcel.protection_max_cover', $scope);
            if ($declaredValue <= 0 || $declaredValue > $cover) {
                throw new ParcelQuoteException('Declare a value between 1 and '.$this->num($cover).'.');
            }
        }
        if ($returnTrip && ! ParcelSettings::returnTripAvailable($scope)) {
            throw new ParcelQuoteException('Return to sender is not available here.');
        }

        $factor = (float) ParcelSettings::get('parcel.road_distance_factor', $scope);
        $maxDistance = (float) ParcelSettings::get('parcel.max_distance_km', $scope);
        // The longest delivery accepted is how far any drop point may be from the pickup (the service radius).
        foreach (array_merge([[$dropLat, $dropLng]], $extraDrops) as $i => [$lat, $lng]) {
            $away = round($this->dispatch->haversineKm($pickupLat, $pickupLng, (float) $lat, (float) $lng) * $factor, 2);
            if ($away > $maxDistance) {
                $which = $extraDrops === [] ? 'That drop point' : 'Drop '.($i + 1);
                throw new ParcelQuoteException("{$which} is {$away} km away. We deliver parcels up to {$maxDistance} km.");
            }
        }

        // Route distance: pickup → drop 1 → drop 2 → …
        $straight = 0.0;
        $points = array_merge([[$pickupLat, $pickupLng], [$dropLat, $dropLng]], $extraDrops);
        for ($i = 1; $i < count($points); $i++) {
            $straight += $this->dispatch->haversineKm((float) $points[$i - 1][0], (float) $points[$i - 1][1], (float) $points[$i][0], (float) $points[$i][1]);
        }
        $distance = round($straight * $factor, 2);

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
        if ($extraDrops !== []) {
            $count = count($extraDrops);
            $lines[] = ['label' => $count === 1 ? '1 extra drop point' : "{$count} extra drop points", 'amount' => round($count * (float) ParcelSettings::get('parcel.extra_stop_fee', $scope), 2)];
        }
        // The base fare always shows; a zero distance/weight/size line is noise.
        $lines = array_values(array_filter($lines, fn ($line, $i) => $i === 0 || $line['amount'] > 0, ARRAY_FILTER_USE_BOTH));

        if ($returnTrip) {
            $percent = (float) ParcelSettings::get('parcel.return_trip_percent', $scope);
            $lines[] = ['label' => 'Return to sender ('.$this->num($percent).'%)', 'amount' => round(array_sum(array_column($lines, 'amount')) * $percent / 100, 2)];
        }

        // Protection is not part of the delivery price: it is added last and never shared with the rider.
        $protectionFee = $declaredValue !== null ? ParcelSettings::protectionFee($declaredValue, $scope) : 0.0;
        if ($declaredValue !== null) {
            $lines[] = ['label' => 'Parcel protection (value '.$this->num($declaredValue).')', 'amount' => $protectionFee];
        }

        return [
            'total' => round(array_sum(array_column($lines, 'amount')), 2),
            'distance_km' => $distance,
            'lines' => $lines,
            'protection_fee' => $protectionFee,
        ];
    }

    private function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
