<?php

namespace App\Services\Food;

use App\Jobs\MarketplaceDispatchJob;
use App\Models\MarketplaceOrder;
use App\Support\FoodOrderClock;

/**
 * Food F2 — calls a rider while the food is still cooking, food.rider_call_before_ready_minutes before the time the
 * restaurant promised (ready_by), so the rider arrives as it is packed. Unset = nothing happens here: the rider is
 * called when the restaurant marks the food ready, as for shops. Runs when the restaurant starts cooking and every
 * minute from food:call-riders. Calls once per order (rider_called_at is set under a conditional update).
 */
class FoodRiderCaller
{
    public function callIfDue(MarketplaceOrder $order): bool
    {
        if (! $order->isFood() || $order->order_type !== 'delivery' || $order->status !== 'preparing'
            || $order->rider_called_at !== null || $order->assigned_worker_id !== null || $order->ready_by === null) {
            return false;
        }

        $lead = FoodOrderClock::riderLeadMinutes($order);
        if ($lead === null || now()->lt($order->ready_by->copy()->subMinutes($lead))) {
            return false;
        }

        $claimed = MarketplaceOrder::whereKey($order->id)->where('status', 'preparing')->whereNull('rider_called_at')
            ->update(['rider_called_at' => now()]);
        if ($claimed !== 1) {
            return false;
        }

        $order->statusHistory()->create([
            'status' => 'preparing',
            'note' => 'Rider called while the food is cooking (ready by '.$order->readyByLocal().')',
            'changed_at' => now(),
        ]);

        MarketplaceDispatchJob::dispatch($order->id);

        return true;
    }
}
