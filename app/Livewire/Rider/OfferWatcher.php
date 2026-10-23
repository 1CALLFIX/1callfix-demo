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
        $this->online = $rider->is_online && $this->parcelActiveFor($rider) && ! $service->activeJob($rider);
        $offers = $this->online ? $service->liveOffers($rider) : collect();

        $this->dispatch('provider-alert-offers', count: $offers->count(), offers: $service->alertSummaries($offers));

        return <<<'BLADE'
            <div @if ($online) wire:poll.5s @endif aria-hidden="true"></div>
        BLADE;
    }
}
