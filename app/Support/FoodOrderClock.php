<?php

namespace App\Support;

use App\Models\MarketplaceOrder;

/**
 * Food F2 — the two clocks on a restaurant order, both from Food Settings / the restaurant's page:
 *  - accept_by: the restaurant must accept within food.accept_minutes of the order being placed (cash, wallet) or
 *    paid (online). Missed = food:expire-unaccepted cancels it and refunds in full.
 *  - ready_by: set when the restaurant accepts — now + its cooking time (Store::currentPrepMinutes(), which adds the
 *    busy minutes). Shown to the customer, and the rider is called food.rider_call_before_ready_minutes before it.
 */
class FoodOrderClock
{
    public static function scopeFor(MarketplaceOrder $order): array
    {
        $order->loadMissing('franchise');

        return array_filter([
            'zone_id' => $order->zone_id, 'franchise_id' => $order->franchise_id,
            'city_id' => $order->franchise?->city_id, 'country_id' => $order->franchise?->country_id,
        ]);
    }

    public static function acceptMinutes(MarketplaceOrder $order): int
    {
        return max(1, (int) FoodSettings::get('food.accept_minutes', self::scopeFor($order)));
    }

    /** Idempotent: the first start wins (a repeated payment webhook does not give the restaurant more time). */
    public static function startAcceptClock(MarketplaceOrder $order): void
    {
        if (! $order->isFood() || $order->accept_by !== null) {
            return;
        }

        $order->forceFill(['accept_by' => now()->addMinutes(self::acceptMinutes($order))])->save();
    }

    /** Cooking time the restaurant is promising right now (its own or the default, plus busy minutes). */
    public static function prepMinutes(MarketplaceOrder $order): int
    {
        $order->loadMissing('store.franchise');

        return max(1, (int) ($order->store?->currentPrepMinutes() ?? FoodSettings::get('food.default_prep_minutes', self::scopeFor($order))));
    }

    /** Null = not set: the rider is called when the food is marked ready (as for shops). */
    public static function riderLeadMinutes(MarketplaceOrder $order): ?int
    {
        $value = FoodSettings::get('food.rider_call_before_ready_minutes', self::scopeFor($order));

        return $value === null || $value === '' ? null : max(0, (int) $value);
    }
}
