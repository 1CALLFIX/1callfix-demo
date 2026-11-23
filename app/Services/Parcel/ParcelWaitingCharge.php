<?php

namespace App\Services\Parcel;

use App\Models\ParcelOrder;
use Illuminate\Support\Carbon;

/**
 * Parcel Stage 1 (2026-10-25): the waiting charge at pickup. The clock starts when the rider taps "I've arrived"
 * (MarkParcelArrivedAtPickupAction, location-checked) and stops at pickup. The first
 * parcel.waiting_free_minutes are free; after that every started block of parcel.waiting_block_minutes costs
 * parcel.waiting_charge_per_block. Off unless both the free time and the rate are set.
 *
 * The sender pays it to the rider at pickup (cash or UPI). It is the rider's own money: it is not part of the
 * delivery price, takes no commission and creates no cash due. The rider screen and the pickup action use this
 * one calculation, so what the rider asks for is what is recorded.
 */
class ParcelWaitingCharge
{
    /** @return array{minutes: int, charge: float} */
    public static function compute(ParcelOrder $order, ?Carbon $at = null): array
    {
        if (! $order->arrived_at_pickup_at) {
            return ['minutes' => 0, 'charge' => 0.0];
        }

        $minutes = (int) floor(max(0, $order->arrived_at_pickup_at->diffInSeconds($at ?? now(), false)) / 60);
        $scope = self::scope($order);
        if (! ParcelSettings::waitingChargeEnabled($scope)) {
            return ['minutes' => $minutes, 'charge' => 0.0];
        }

        ['free' => $free, 'block' => $block, 'rate' => $rate] = self::terms($order, $scope);
        $blocks = (int) ceil(max(0, $minutes - $free) / $block);

        return ['minutes' => $minutes, 'charge' => round($blocks * $rate, 2)];
    }

    /**
     * Free minutes, block length and charge per block for this order. Stage 3: a goods vehicle may have its own free
     * loading time and rate; blank = the parcel values.
     *
     * @return array{free: int, block: int, rate: float}
     */
    public static function terms(ParcelOrder $order, ?array $scope = null): array
    {
        $scope ??= self::scope($order);
        $free = (int) ParcelSettings::get('parcel.waiting_free_minutes', $scope);
        $rate = (float) ParcelSettings::get('parcel.waiting_charge_per_block', $scope);
        if (ParcelSettings::isGoodsVehicle($order->vehicle_type)) {
            $free = (int) (ParcelSettings::get(ParcelSettings::vehicleKey($order->vehicle_type, 'free_loading_minutes'), $scope) ?? $free);
            $rate = (float) (ParcelSettings::get(ParcelSettings::vehicleKey($order->vehicle_type, 'waiting_charge_per_block'), $scope) ?? $rate);
        }

        return ['free' => $free, 'block' => max(1, (int) ParcelSettings::get('parcel.waiting_block_minutes', $scope)), 'rate' => $rate];
    }

    public static function scope(ParcelOrder $order): array
    {
        $order->loadMissing('franchise');

        return array_filter([
            'zone_id' => $order->zone_id, 'franchise_id' => $order->franchise_id,
            'city_id' => $order->franchise?->city_id, 'country_id' => $order->franchise?->country_id,
        ]);
    }
}
