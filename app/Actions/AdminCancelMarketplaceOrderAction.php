<?php

namespace App\Actions;

use App\Events\MarketplaceOrderStatusUpdated;
use App\Models\MarketplaceOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Notifications\MarketplaceOrderStatusNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\CancellationService;
use Illuminate\Support\Facades\DB;

/**
 * Phase 24 (Marketplace Foundation) — the MarketplaceOrder counterpart to
 * AdminCancelPropertyReservationAction, with the same real difference that
 * one needed: cancelling an order must also release its decremented stock
 * back (the checkout-time counterpart to PropertyAvailabilityService::
 * releaseDates()), inside the SAME transaction as the status change.
 */
class AdminCancelMarketplaceOrderAction
{
    public function __construct(private CancellationService $cancellationService)
    {
    }

    /**
     * E-commerce Phase 1: `$by` = 'seller' when the shop rejects or cannot fill the order (Seller → Orders). That is
     * never the customer's doing, so no cancellation fee is charged and the customer is refunded in full, and a
     * seller cannot cancel once the order is ready (with a rider or waiting for collection).
     */
    public function execute(int $orderId, string $reason, string $by = 'admin'): MarketplaceOrder
    {
        $order = DB::transaction(function () use ($orderId, $reason, $by) {
            $order = MarketplaceOrder::lockForUpdate()->with('items')->findOrFail($orderId);

            if (in_array($order->status, ['completed', 'cancelled'], true)) {
                throw new \RuntimeException("Order is already {$order->status}, cannot cancel.");
            }

            if ($by === 'seller' && ! in_array($order->status, ['pending', 'accepted', 'preparing'], true)) {
                throw new \RuntimeException('This order is ready and can no longer be cancelled by the shop. Contact support.');
            }

            $fee = $by === 'seller' ? 0.0 : $this->cancellationService->calculateFeeForMarketplaceOrder($order);

            $this->releaseStock($order);

            $order->status = 'cancelled';
            $order->cancellation_note = $reason;
            $order->cancellation_fee = $fee;
            $order->save();

            $order->statusHistory()->create([
                'status' => 'cancelled',
                'note' => "Cancelled by {$by}: {$reason}".($fee > 0 ? " (cancellation fee: {$fee})" : ''),
                'changed_at' => now(),
            ]);

            event(new MarketplaceOrderStatusUpdated($order));

            return $order->fresh();
        });

        $this->cancellationService->refundIfPaidForMarketplaceOrder($order, (float) $order->cancellation_fee);

        if ($order->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $order->zone_id, 'franchise_id' => $order->franchise_id]);
            $order->customer->notify(new MarketplaceOrderStatusNotification('cancelled', $order, $channels));
        }

        return $order->fresh();
    }

    private function releaseStock(MarketplaceOrder $order): void
    {
        $variantIds = $order->items->whereNotNull('product_variant_id')->pluck('product_variant_id')->unique()->sort()->values();
        $productIdsWithoutVariant = $order->items->whereNull('product_variant_id')->pluck('product_id')->unique()->sort()->values();

        if ($variantIds->isNotEmpty()) {
            ProductVariant::whereIn('id', $variantIds)->lockForUpdate()->get();
        }
        if ($productIdsWithoutVariant->isNotEmpty()) {
            Product::whereIn('id', $productIdsWithoutVariant)->lockForUpdate()->get();
        }

        foreach ($order->items as $item) {
            if ($item->product_variant_id) {
                ProductVariant::where('id', $item->product_variant_id)->increment('stock', $item->quantity);
            } elseif ($item->product_id) {
                Product::where('id', $item->product_id)->increment('stock', $item->quantity);
            }
        }
    }
}
