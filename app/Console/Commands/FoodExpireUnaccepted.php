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

        // Restaurants with no billing software (owner 2026-11-12): before cancelling, remind the owner once.
        $nudged = 0;
        MarketplaceOrder::with(['franchise', 'store.provider.user'])
            ->where('module', Modules::FOOD)->where('status', 'pending')
            ->whereNotNull('accept_by')->where('accept_by', '>', now())->whereNull('accept_nudged_at')
            ->orderBy('id')
            ->chunkById(100, function ($orders) use (&$nudged) {
                foreach ($orders as $order) {
                    $scope = FoodOrderClock::scopeFor($order);
                    $after = (int) \App\Support\FoodSettings::get('food.nudge_after_minutes', $scope);
                    $owner = $order->store?->provider?->user;
                    $since = $order->accept_by->copy()->subMinutes(FoodOrderClock::acceptMinutes($order));
                    if ($after <= 0 || ! $owner || $since->copy()->addMinutes($after)->isFuture()) {
                        continue;
                    }
                    $order->forceFill(['accept_nudged_at' => now()])->save();
                    rescue(fn () => $owner->notify(new \App\Notifications\FoodOrderWaitingNotification($order,
                        \App\Notifications\Support\ChannelResolver::resolve($scope), max(1, (int) ceil(now()->diffInSeconds($order->accept_by) / 60)))));
                    $nudged++;
                }
            });

        $this->info("Food accept sweep: {$expired} cancelled, {$nudged} reminded.");

        return self::SUCCESS;
    }
}
