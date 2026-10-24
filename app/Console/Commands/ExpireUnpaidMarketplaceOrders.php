<?php

namespace App\Console\Commands;

use App\Actions\AdminCancelMarketplaceOrderAction;
use App\Models\MarketplaceOrder;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * E-commerce Phase 0 — cancels online shop orders whose payment never arrived within
 * marketplace.unpaid_expiry_minutes (per order's own location; default 30), which puts their stock back. The
 * seller never saw such an order. A payment captured later is refunded by MarketplacePaymentService::markPaid().
 */
class ExpireUnpaidMarketplaceOrders extends Command
{
    protected $signature = 'marketplace:expire-unpaid';

    protected $description = 'Cancels online shop orders not paid within marketplace.unpaid_expiry_minutes';

    public function handle(AdminCancelMarketplaceOrderAction $cancel): int
    {
        $expired = 0;

        MarketplaceOrder::with('franchise')
            ->where('status', 'pending')
            ->where('payment_method', 'online')
            ->where('payment_status', 'pending')
            ->where('created_at', '<=', now()->subMinutes(5)) // the smallest sensible window
            ->orderBy('id')
            ->chunkById(100, function ($orders) use ($cancel, &$expired) {
                foreach ($orders as $order) {
                    $minutes = max(5, (int) Setting::get('marketplace.unpaid_expiry_minutes', '30', array_filter([
                        'zone_id' => $order->zone_id, 'franchise_id' => $order->franchise_id,
                        'city_id' => $order->franchise?->city_id, 'country_id' => $order->franchise?->country_id,
                    ])));

                    if ($order->created_at->gt(now()->subMinutes($minutes))) {
                        continue;
                    }

                    try {
                        $cancel->execute($order->id, "Payment not received within {$minutes} minutes");
                        $expired++;
                    } catch (\Throwable $e) {
                        Log::warning("marketplace:expire-unpaid could not cancel order [{$order->id}]: ".$e->getMessage());
                    }
                }
            });

        $this->info("Shop unpaid sweep: {$expired} cancelled.");

        return self::SUCCESS;
    }
}
