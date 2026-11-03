<?php

namespace App\Livewire\Rider;

use App\Livewire\Rider\Concerns\HandlesShopJobs;
use App\Livewire\Rider\Concerns\InteractsWithRider;
use App\Models\Setting;
use App\Services\Marketplace\MarketplaceReturnPickupService as PICKUP;
use App\Services\Parcel\RiderOfferService;
use Livewire\Component;

/**
 * E-commerce Phase 3 — Rider → Shop deliveries. Since the rider app rebuild (2026-11-04) Home runs every job —
 * parcel, shop, food and return pickups — on one screen, and this page is no longer in the menu; it stays for old
 * links and bookmarks and runs the same steps (Concerns\HandlesShopJobs).
 */
class ShopDeliveries extends Component
{
    use HandlesShopJobs;
    use InteractsWithRider;

    public string $otp = '';

    public string $notice = '';

    public string $error = '';

    public function accept(int $orderId): void
    {
        $this->acceptShopOffer($orderId);
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
        $this->declineShopOffer($orderId);
    }

    public function deliver(): void
    {
        if ($this->deliverShopWith($this->otp)) {
            $this->otp = '';
        }
    }

    public function acceptPickup(int $returnId): void
    {
        $this->acceptReturnPickup($returnId);
    }

    public function collectReturn(): void
    {
        if ($this->collectReturnWith($this->otp)) {
            $this->otp = '';
        }
    }

    public function dropReturn(): void
    {
        if ($this->dropReturnWith($this->otp)) {
            $this->otp = '';
        }
    }

    public function render()
    {
        $rider = $this->rider();
        $service = app(RiderOfferService::class);
        $job = $service->activeShopJob($rider);
        $busyWithParcel = $service->activeJob($rider) !== null;
        $returnJob = app(PICKUP::class)->activeFor($rider);
        $offers = ($rider->is_online && ! $job && ! $busyWithParcel && ! $returnJob) ? $service->liveShopOffers($rider) : collect();
        $pickups = ($job || $busyWithParcel || $returnJob) ? collect() : app(PICKUP::class)->openPickupsFor($rider);
        $this->dispatch('provider-alert-offers', count: $offers->count(), offers: $service->shopAlertSummaries($offers));

        return view('livewire.rider.shop-deliveries', [
            'rider' => $rider,
            'job' => $job,
            'busyWithParcel' => $busyWithParcel,
            'offers' => $offers,
            'returnJob' => $returnJob,
            'pickups' => $pickups,
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ])->layout('components.layouts.rider', ['title' => 'Shop deliveries']);
    }
}
