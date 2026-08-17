<?php

namespace App\Jobs;

use App\Models\DispatchAttempt;
use App\Models\MarketplaceOrder;
use App\Models\Setting;
use App\Services\DispatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 24 (Marketplace Foundation) — a close structural mirror of
 * ParcelDispatchJob (self-requeuing, round-limited, offer-timeout), calling
 * DispatchService::findMarketplaceDeliveryRiderCandidates() instead.
 * Deliberately NOT triggered on order creation -- only once an order
 * reaches `ready` (AdvanceMarketplaceOrderAction) AND is `order_type =
 * delivery`; a `pickup` order never dispatches at all. Unlike Parcel, this
 * job does not itself change the order's own `status` (there is no
 * separate "searching" state -- `ready` already covers "waiting for a
 * rider" the same way it covers "waiting for the customer to collect" for
 * pickup orders, see architecture doc §4a); it only creates/times out
 * `dispatch_attempts` offer rows.
 */
class MarketplaceDispatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private function batchSize(): int
    {
        return (int) Setting::get('marketplace.dispatch.offer_batch_size', 5);
    }

    private function offerTimeoutSeconds(): int
    {
        return (int) Setting::get('marketplace.dispatch.offer_timeout_seconds', 25);
    }

    private function maxRounds(): int
    {
        return (int) Setting::get('marketplace.dispatch.max_rounds', 6);
    }

    public function __construct(
        public int $marketplaceOrderId,
        public int $round = 1,
    ) {
    }

    public function handle(DispatchService $dispatchService): void
    {
        $order = MarketplaceOrder::find($this->marketplaceOrderId);

        if (! $order || $order->status !== 'ready' || $order->order_type !== 'delivery' || $order->assigned_worker_id !== null) {
            return;
        }

        $this->timeoutExpiredAttempts($order);

        $maxRounds = $this->maxRounds();

        if ($this->round > $maxRounds) {
            Log::warning("MarketplaceDispatchJob: exhausted {$maxRounds} rounds for order [{$order->id}] with no acceptance — leaving for manual admin assignment.");
            return;
        }

        $offerTimeoutSeconds = $this->offerTimeoutSeconds();
        $candidates = $dispatchService->findMarketplaceDeliveryRiderCandidates($order, $this->batchSize());

        if ($candidates->isEmpty()) {
            self::dispatch($this->marketplaceOrderId, $this->round + 1)
                ->delay(now()->addSeconds($offerTimeoutSeconds));
            return;
        }

        foreach ($candidates as $candidate) {
            DispatchAttempt::create([
                'dispatchable_type' => MarketplaceOrder::class,
                'dispatchable_id' => $order->id,
                'notifiable_type' => \App\Models\FieldWorker::class,
                'notifiable_id' => $candidate['provider']->id,
                'status' => 'notified',
                'distance_km' => $candidate['distance_km'],
                'notified_at' => now(),
            ]);
        }

        self::dispatch($this->marketplaceOrderId, $this->round + 1)
            ->delay(now()->addSeconds($offerTimeoutSeconds));
    }

    private function timeoutExpiredAttempts(MarketplaceOrder $order): void
    {
        DispatchAttempt::where('dispatchable_type', MarketplaceOrder::class)
            ->where('dispatchable_id', $order->id)
            ->where('status', 'notified')
            ->where('notified_at', '<=', now()->subSeconds($this->offerTimeoutSeconds()))
            ->update(['status' => 'timeout', 'responded_at' => now()]);
    }
}
