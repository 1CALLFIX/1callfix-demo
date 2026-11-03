<?php

namespace App\Console\Commands;

use App\Models\MarketplaceOrder;
use App\Services\Food\FoodRiderCaller;
use App\Support\Modules;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Food F2 — every minute: calls a rider for each cooking food order whose ready time is within
 * food.rider_call_before_ready_minutes. Does nothing while that setting is unset.
 */
class FoodCallRiders extends Command
{
    protected $signature = 'food:call-riders';

    protected $description = 'Calls riders for food orders about to be ready (food.rider_call_before_ready_minutes)';

    public function handle(FoodRiderCaller $caller): int
    {
        $called = 0;

        MarketplaceOrder::with('franchise')
            ->where('module', Modules::FOOD)->where('order_type', 'delivery')->where('status', 'preparing')
            ->whereNull('rider_called_at')->whereNull('assigned_worker_id')->whereNotNull('ready_by')
            ->where('ready_by', '<=', now()->addHours(4)) // the longest cooking time a restaurant can confirm
            ->orderBy('id')
            ->chunkById(100, function ($orders) use ($caller, &$called) {
                foreach ($orders as $order) {
                    try {
                        $called += $caller->callIfDue($order) ? 1 : 0;
                    } catch (\Throwable $e) {
                        Log::warning("food:call-riders could not call a rider for order [{$order->id}]: ".$e->getMessage());
                    }
                }
            });

        $this->info("Food rider calls: {$called}.");

        return self::SUCCESS;
    }
}
