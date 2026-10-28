<?php

namespace App\Livewire\Rider;

use App\Actions\AcceptMarketplaceDeliveryOfferAction;
use App\Actions\CompleteMarketplaceOrderAction;
use App\Livewire\Rider\Concerns\InteractsWithRider;
use App\Models\DispatchAttempt;
use App\Models\FieldWorker;
use App\Models\MarketplaceOrder;
use App\Models\Setting;
use App\Services\Parcel\RiderOfferService;
use Livewire\Component;

/**
 * E-commerce Phase 3 — Rider → Shop deliveries. The same rider, the same online switch (set on Home), one job at a
 * time across parcel and shop. Offers to accept or decline; then the job: go to the shop (it is packed and waiting),
 * take it to the customer, collect cash on a cash order, and finish with the customer's code. Every write goes
 * through the marketplace Actions (AcceptMarketplaceDeliveryOfferAction, CompleteMarketplaceOrderAction).
 */
class ShopDeliveries extends Component
{
    use InteractsWithRider;

    public string $otp = '';

    public string $notice = '';

    public string $error = '';

    public function accept(int $orderId, AcceptMarketplaceDeliveryOfferAction $action): void
    {
        $this->reset('notice', 'error');
        $rider = $this->rider();
        if (app(RiderOfferService::class)->activeJob($rider) || app(RiderOfferService::class)->activeShopJob($rider)) {
            $this->error = 'Finish your current job first.';

            return;
        }
        try {
            $action->execute($orderId, $rider);
            $this->notice = 'Accepted. Head to the shop — the order is packed and waiting.';
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
        }
    }

    /** Location heartbeat while this page is open (Home sends the same), so offers keep coming. */
    public function heartbeat(?float $lat = null, ?float $lng = null): void
    {
        $rider = $this->rider();
        if ($rider->is_online && $lat !== null && $lng !== null && abs($lat) <= 90 && abs($lng) <= 180) {
            app(RiderOfferService::class)->setOnline($rider, true, $lat, $lng);
        }
    }

    public function decline(int $orderId): void
    {
        DispatchAttempt::where('dispatchable_type', MarketplaceOrder::class)->where('dispatchable_id', $orderId)
            ->where('notifiable_type', FieldWorker::class)->where('notifiable_id', $this->rider()->id)
            ->where('status', 'notified')->update(['status' => 'rejected', 'responded_at' => now()]);
    }

    public function deliver(CompleteMarketplaceOrderAction $action): void
    {
        $this->reset('notice', 'error');
        $job = app(RiderOfferService::class)->activeShopJob($this->rider());
        if (! $job) {
            return;
        }
        try {
            $action->execute($job->id, $this->rider(), trim($this->otp));
            $this->otp = '';
            $this->notice = 'Delivered. Thank you!';
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage() === 'Incorrect delivery OTP.' ? 'That code is not right. Ask the customer to read it from their order page.' : $e->getMessage();
        }
    }

    public function render()
    {
        $rider = $this->rider();
        $service = app(RiderOfferService::class);
        $job = $service->activeShopJob($rider);
        $busyWithParcel = $service->activeJob($rider) !== null;
        $offers = ($rider->is_online && ! $job && ! $busyWithParcel) ? $service->liveShopOffers($rider) : collect();
        $this->dispatch('provider-alert-offers', count: $offers->count(), offers: $service->shopAlertSummaries($offers));

        return view('livewire.rider.shop-deliveries', [
            'rider' => $rider,
            'job' => $job,
            'busyWithParcel' => $busyWithParcel,
            'offers' => $offers,
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ])->layout('components.layouts.rider', ['title' => 'Shop deliveries']);
    }
}
