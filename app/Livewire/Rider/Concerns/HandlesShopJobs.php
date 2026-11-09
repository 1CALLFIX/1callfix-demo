<?php

namespace App\Livewire\Rider\Concerns;

use App\Actions\AcceptMarketplaceDeliveryOfferAction;
use App\Actions\CompleteMarketplaceOrderAction;
use App\Models\DispatchAttempt;
use App\Models\FieldWorker;
use App\Models\MarketplaceOrder;
use App\Services\Marketplace\MarketplaceReturnPickupService;
use App\Services\Parcel\RiderOfferService;

/**
 * Rider app 2026-11-04 (owner: one rider takes parcel, shop and food jobs, so one screen must run them all): the
 * shop / food delivery and return-pickup steps, shared by Home (Dashboard) and the old Shop deliveries page. Every
 * write still goes through the marketplace Actions and services. Expects $notice and $error on the component.
 */
trait HandlesShopJobs
{
    protected function busyWithAnyJob(FieldWorker $rider): bool
    {
        $offers = app(RiderOfferService::class);

        return $offers->activeJob($rider) !== null || $offers->activeShopJob($rider) !== null
            || app(MarketplaceReturnPickupService::class)->isBusy($rider);
    }

    public function acceptShopOffer(int $orderId): void
    {
        $this->reset('notice', 'error');
        $rider = $this->rider();
        if ($this->busyWithAnyJob($rider)) {
            $this->error = 'Finish your current job first.';

            return;
        }
        try {
            app(AcceptMarketplaceDeliveryOfferAction::class)->execute($orderId, $rider);
            $food = MarketplaceOrder::whereKey($orderId)->value('module') === \App\Support\Modules::FOOD;
            $this->notice = $food
                ? 'Accepted. Head to the restaurant; take the food once they mark it ready.'
                : 'Accepted. Head to the shop — the order is packed and waiting.';
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function declineShopOffer(int $orderId): void
    {
        DispatchAttempt::where('dispatchable_type', MarketplaceOrder::class)->where('dispatchable_id', $orderId)
            ->where('notifiable_type', FieldWorker::class)->where('notifiable_id', $this->rider()->id)
            ->where('status', 'notified')->update(['status' => 'rejected', 'responded_at' => now()]);
    }

    /**
     * Owner 2026-11-09: "I've reached the restaurant". Before this the rider sees only the restaurant and how far the
     * customer is; after it, the items to collect, the customer and the delivery.
     */
    public function reachedStore(): void
    {
        $this->reset('notice', 'error');
        $job = app(RiderOfferService::class)->activeShopJob($this->rider());
        if (! $job || $job->rider_at_store_at !== null) {
            return;
        }
        $job->forceFill(['rider_at_store_at' => now()])->save();
        $job->statusHistory()->create([
            'status' => $job->status,
            'changed_by' => $this->rider()->user_id,
            'note' => 'Rider reached the '.($job->isFood() ? 'restaurant' : 'shop'),
            'changed_at' => now(),
        ]);
        $this->notice = 'Show the order number '.$job->code.' at the counter and check every item before you leave.';
    }

    protected function deliverShopWith(string $code): bool
    {
        $this->reset('notice', 'error');
        $job = app(RiderOfferService::class)->activeShopJob($this->rider());
        if (! $job) {
            return false;
        }
        if ($job->rider_at_store_at === null) {
            $this->error = 'Tap "I\'ve reached the '.($job->isFood() ? 'restaurant' : 'shop').'" first.';

            return false;
        }
        try {
            app(CompleteMarketplaceOrderAction::class)->execute($job->id, $this->rider(), trim($code));
            $this->notice = 'Delivered. Thank you!';

            return true;
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage() === 'Incorrect delivery OTP.' ? 'That code is not right. Ask the customer to read it from their order page.' : $e->getMessage();

            return false;
        }
    }

    public function acceptReturnPickup(int $returnId): void
    {
        $this->reset('notice', 'error');
        try {
            app(MarketplaceReturnPickupService::class)->accept($returnId, $this->rider());
            $this->notice = 'Accepted. Go to the customer and ask for the pickup code.';
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
        }
    }

    protected function collectReturnWith(string $code): bool
    {
        $this->reset('notice', 'error');
        try {
            app(MarketplaceReturnPickupService::class)->confirmPickup($this->rider(), $code);
            $this->notice = 'Collected. Take it to the shop and ask for the drop code.';

            return true;
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return false;
        }
    }

    protected function dropReturnWith(string $code): bool
    {
        $this->reset('notice', 'error');
        try {
            app(MarketplaceReturnPickupService::class)->confirmDrop($this->rider(), $code);
            $this->notice = 'Handed back to the shop. Your fee is in your wallet. Thank you!';

            return true;
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return false;
        }
    }
}
