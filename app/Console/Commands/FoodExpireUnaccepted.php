<?php

namespace App\Console\Commands;

use App\Actions\AdminCancelMarketplaceOrderAction;
use App\Models\MarketplaceOrder;
use App\Support\FoodOrderClock;
use App\Support\Modules;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Food F2 — every minute: a food order the restaurant did not accept by accept_by (food.accept_minutes after it was
 * placed or paid; owner decision 2026-11-02: 10 minutes) is cancelled as the restaurant's doing: no fee, full refund
 * to the customer through the usual cancellation refund.
 */
class FoodExpireUnaccepted extends Command
{
    protected $signature = 'food:expire-unaccepted';

    protected $description = 'Cancels and refunds food orders a restaurant did not accept within food.accept_minutes';

    public function handle(AdminCancelMarketplaceOrderAction $cancel): int
    {
        $expired = 0;

        MarketplaceOrder::with('franchise')
            ->where('module', Modules::FOOD)->where('status', 'pending')
            ->whereNotNull('accept_by')->where('accept_by', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($orders) use ($cancel, &$expired) {
                foreach ($orders as $order) {
                    try {
                        $minutes = FoodOrderClock::acceptMinutes($order);
                        $cancel->execute($order->id, "The restaurant did not accept the order within {$minutes} minutes", 'seller');
                        $expired++;
                    } catch (\Throwable $e) {
                        Log::warning("food:expire-unaccepted could not cancel order [{$order->id}]: ".$e->getMessage());
                    }
                }
            });

        $this->info("Food accept sweep: {$expired} cancelled.");

        return self::SUCCESS;
    }
}
