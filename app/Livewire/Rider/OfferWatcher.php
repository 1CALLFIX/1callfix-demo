<?php

namespace App\Livewire\Rider;

use App\Livewire\Rider\Concerns\InteractsWithRider;
use App\Services\Parcel\RiderOfferService;
use Livewire\Component;

/**
 * Offer source for the rider pages that don't poll offers themselves (History, Earnings) — the rider
 * counterpart of Provider\OfferWatcher. Display only: it re-emits `provider-alert-offers` so the layout's
 * ring banner works on every page; accepting happens on the dashboard.
 */
class OfferWatcher extends Component
{
    use InteractsWithRider;

    public bool $online = false;

    public function render()
    {
        $rider = $this->rider();
        $service = app(RiderOfferService::class);
        // E-commerce Phase 3: shop delivery offers ring too, on every rider page (one job at a time across both).
        $this->online = $rider->is_online && ! $service->activeJob($rider) && ! $service->activeShopJob($rider);
        $offers = ($this->online && $this->parcelActiveFor($rider)) ? $service->liveOffers($rider) : collect();
        $shopOffers = $this->online ? $service->liveShopOffers($rider) : collect();

        $this->dispatch('provider-alert-offers', count: $offers->count() + $shopOffers->count(),
            offers: array_merge($service->alertSummaries($offers), $service->shopAlertSummaries($shopOffers)));

        return <<<'BLADE'
            <div @if ($online) wire:poll.5s @endif aria-hidden="true"></div>
        BLADE;
    }
}
