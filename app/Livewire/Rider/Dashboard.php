<?php

namespace App\Livewire\Rider;

use App\Actions\AcceptParcelOfferAction;
use App\Actions\DeclineParcelOfferAction;
use App\Actions\MarkParcelDeliveredAction;
use App\Actions\MarkParcelEnRouteAction;
use App\Actions\MarkParcelPickedUpAction;
use App\Actions\ParcelSenderPaidAtDoorAction;
use App\Livewire\Rider\Concerns\InteractsWithRider;
use App\Models\Setting;
use App\Services\Parcel\FieldWorkerCashDueService;
use App\Services\Parcel\RiderOfferService;
use Livewire\Component;

/**
 * Rider home (owner decision 2026-10-23: riders on a web area). One screen for the whole job, because a rider
 * carries one parcel at a time: the online switch (with a location heartbeat), live offers with Accept /
 * Decline (and the shared ring banner), and the current job — On my way, pickup OTP (collect cash first on a
 * cash order), delivery OTP. Every write goes through the existing parcel Actions.
 */
class Dashboard extends Component
{
    use InteractsWithRider;

    public string $pickupOtp = '';

    public string $deliveryOtp = '';

    public string $notice = '';

    public string $error = '';

    /** Called by the page's JS after a GPS fix (or null, null) — and again every few minutes as a heartbeat. */
    public function goOnline(?float $lat = null, ?float $lng = null): void
    {
        $this->reset('notice', 'error');
        // Resolved here rather than injected: this signature takes caller-supplied optional args (same reason
        // as Provider\Dashboard::goOnline()).
        $offers = app(RiderOfferService::class);
        $rider = $this->rider();

        if ($lat !== null && ($lat < -90 || $lat > 90 || $lng === null || $lng < -180 || $lng > 180)) {
            $lat = $lng = null;
        }

        $wasOnline = $rider->is_online;
        $offers->setOnline($rider, true, $lat, $lng);

        if (! $wasOnline) {
            $this->notice = $lat !== null
                ? "You're online. Keep this page open to receive parcel offers."
                : "You're online, but we couldn't read your location — you won't get offers until we can. Allow location access.";
        }
    }

    public function goOffline(RiderOfferService $offers): void
    {
        $this->reset('notice', 'error');
        $offers->setOnline($this->rider(), false);
        $this->notice = "You're offline. You won't get new offers.";
    }

    public function accept(int $orderId, AcceptParcelOfferAction $action): void
    {
        $this->reset('notice', 'error');
        try {
            $action->execute($orderId, $this->rider());
            $this->notice = 'Accepted. Head to the pickup point.';
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function decline(int $orderId, DeclineParcelOfferAction $action): void
    {
        $this->reset('notice', 'error');
        try {
            $action->execute($orderId, $this->rider());
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function onTheWay(int $orderId, MarkParcelEnRouteAction $action): void
    {
        $this->reset('notice', 'error');
        $rider = $this->rider();
        $this->ownOrder($orderId, $rider);
        try {
            $action->execute($orderId, $rider);
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function confirmPickup(int $orderId, MarkParcelPickedUpAction $action): void
    {
        $this->reset('notice', 'error');
        $rider = $this->rider();
        $this->ownOrder($orderId, $rider);
        try {
            $action->execute($orderId, $rider, trim($this->pickupOtp));
            $this->pickupOtp = '';
            $this->notice = 'Picked up. Deliver it to the recipient.';
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function confirmDelivery(int $orderId, MarkParcelDeliveredAction $action): void
    {
        $this->reset('notice', 'error');
        $rider = $this->rider();
        $this->ownOrder($orderId, $rider);
        try {
            $action->execute($orderId, $rider, trim($this->deliveryOtp));
            $this->deliveryOtp = '';
            $this->notice = 'Delivered. Well done!';
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
        }
    }

    /** Pay at drop, recipient refuses: the rider calls the sender, who pays the rider on the spot (UPI or cash). */
    public function senderPaid(int $orderId, ParcelSenderPaidAtDoorAction $action): void
    {
        $this->reset('notice', 'error');
        $rider = $this->rider();
        $this->ownOrder($orderId, $rider);
        try {
            $action->execute($orderId, $rider);
            $this->notice = 'Recorded: the sender paid you. Now hand over the parcel and enter the delivery code.';
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render()
    {
        $offers = app(RiderOfferService::class);
        $dues = app(FieldWorkerCashDueService::class);
        $rider = $this->rider();
        $active = $this->parcelActiveFor($rider);
        $job = $offers->activeJob($rider);
        $live = ($rider->is_online && $active && ! $job) ? $offers->liveOffers($rider) : collect();

        $this->dispatch('provider-alert-offers', count: $live->count(), offers: $offers->alertSummaries($live));

        return view('livewire.rider.dashboard', [
            'rider' => $rider,
            'parcelActive' => $active,
            'job' => $job,
            'offers' => $live,
            'duesOwed' => $dues->outstanding($rider),
            'overCashLimit' => $dues->overCashLimit($rider, $this->riderScope($rider)),
            'locationFresh' => $rider->location_updated_at && $rider->location_updated_at->gt(now()->subMinutes(max(1, (int) Setting::get('provider.location_stale_after_minutes', '30')))),
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ])->layout('components.layouts.rider', ['title' => 'Home']);
    }
}
