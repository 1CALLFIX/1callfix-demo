<?php

namespace App\Livewire\Rider;

use App\Actions\AcceptParcelOfferAction;
use App\Actions\DeclineParcelOfferAction;
use App\Actions\MarkParcelArrivedAtPickupAction;
use App\Actions\MarkParcelDeliveredAction;
use App\Actions\MarkParcelEnRouteAction;
use App\Actions\MarkParcelPickedUpAction;
use App\Actions\MarkParcelReturnedAction;
use App\Actions\ParcelSenderPaidAtDoorAction;
use App\Actions\RecordParcelSafetyPhotoAction;
use App\Actions\RefuseParcelAtPickupAction;
use App\Livewire\Rider\Concerns\HandlesShopJobs;
use App\Livewire\Rider\Concerns\InteractsWithRider;
use App\Models\Setting;
use App\Services\Parcel\FieldWorkerCashDueService;
use App\Services\Parcel\ParcelSettings;
use App\Services\Parcel\ParcelWaitingCharge;
use App\Services\Parcel\RiderOfferService;
use App\Services\TimezoneResolver;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Rider home (owner decision 2026-10-23: riders on a web area). One screen for the whole job, because a rider
 * carries one parcel at a time: the online switch (with a location heartbeat), live offers with Accept /
 * Decline (and the shared ring banner), and the current job — On my way, pickup OTP (collect cash first on a
 * cash order), delivery OTP. Every write goes through the existing parcel Actions.
 *
 * Rider app 2026-11-04 (owner: the same rider takes parcel, shop and food, so one screen must run them all): Home now
 * also runs shop / food deliveries and return pickups (Concerns\HandlesShopJobs), lists every offer together with a
 * coloured label (Parcel / Shop / Food / Return pickup), and shows one cash-dues figure for all of them.
 */
class Dashboard extends Component
{
    use HandlesShopJobs;
    use InteractsWithRider;
    use WithFileUploads;

    public string $pickupOtp = '';

    public string $deliveryOtp = '';

    /** Stage 2: delivery codes for extra drop points, keyed by stop id, and the sender's return code. */
    public array $stopOtps = [];

    public string $returnOtp = '';

    // Pickup safety (2026-10-29): an optional photo when something looks unusual, and refusing the parcel.
    public $safetyPhoto = null;

    public array $safetyConcerns = [];

    public string $safetyStage = 'before_packing';

    public string $safetyNote = '';

    public string $refuseReason = '';

    public string $refuseNote = '';

    public string $notice = '';

    /** The customer's code on a shop / food delivery, or the return-pickup code. */
    public string $shopOtp = '';

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

        // Signed up on /partners but not yet approved: dispatch would skip them anyway, so do not let them sit
        // "online" waiting for offers that cannot come.
        if ($rider->kyc_status !== 'approved') {
            $this->error = 'Your documents are under review. You can go online once they are approved.';

            return;
        }

        $wasOnline = $rider->is_online;
        $offers->setOnline($rider, true, $lat, $lng);

        if (! $wasOnline) {
            $this->notice = $lat !== null
                ? "You're online. Keep this page open to receive delivery offers."
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

    /** Stage 1: "I've arrived" at pickup, from the page's live GPS fix — starts the waiting-charge clock. */
    public function arrived(int $orderId, ?float $lat = null, ?float $lng = null): void
    {
        $this->reset('notice', 'error');
        $rider = $this->rider();
        $this->ownOrder($orderId, $rider);
        if ($lat !== null && ($lat < -90 || $lat > 90 || $lng === null || $lng < -180 || $lng > 180)) {
            $lat = $lng = null;
        }
        try {
            app(MarkParcelArrivedAtPickupAction::class)->execute($orderId, $rider, $lat, $lng);
            $this->notice = 'Arrival recorded. We told the sender you are here.';
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
            $order = $action->execute($orderId, $rider, trim($this->deliveryOtp));
            $this->deliveryOtp = '';
            $this->notice = $this->afterDropNotice($order);
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
        }
    }

    /** Stage 2: hand over an extra drop point of a multi-drop order with that drop's code. */
    public function confirmStop(int $orderId, int $stopId, MarkParcelDeliveredAction $action): void
    {
        $this->reset('notice', 'error');
        $rider = $this->rider();
        $this->ownOrder($orderId, $rider);
        try {
            $order = $action->execute($orderId, $rider, trim((string) ($this->stopOtps[$stopId] ?? '')), $stopId);
            unset($this->stopOtps[$stopId]);
            $this->notice = $this->afterDropNotice($order);
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
        }
    }

    /** Stage 2: return trip — the return is back with the sender, who gives the return code. */
    public function confirmReturn(int $orderId, MarkParcelReturnedAction $action): void
    {
        $this->reset('notice', 'error');
        $rider = $this->rider();
        $this->ownOrder($orderId, $rider);
        try {
            $action->execute($orderId, $rider, trim($this->returnOtp));
            $this->returnOtp = '';
            $this->notice = 'Returned to the sender. Well done!';
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function deliverShop(): void
    {
        if ($this->deliverShopWith($this->shopOtp)) {
            $this->shopOtp = '';
        }
    }

    public function collectShopReturn(): void
    {
        if ($this->collectReturnWith($this->shopOtp)) {
            $this->shopOtp = '';
        }
    }

    public function dropShopReturn(): void
    {
        if ($this->dropReturnWith($this->shopOtp)) {
            $this->shopOtp = '';
        }
    }

    private function afterDropNotice(\App\Models\ParcelOrder $order): string
    {
        return match (true) {
            $order->status === 'delivered' => 'Delivered. Well done!',
            $order->isReturning() => 'Every drop done. Take the return back to the sender.',
            default => 'Drop done. Head to the next one.',
        };
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

    /** Pickup safety: the rider photographs the item / parcel because something looks unusual (location optional). */
    public function saveSafetyPhoto(int $orderId, ?float $lat = null, ?float $lng = null): void
    {
        $this->reset('notice', 'error');
        $rider = $this->rider();
        $this->ownOrder($orderId, $rider);

        $this->validate([
            'safetyPhoto' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'safetyConcerns' => ['required', 'array', 'min:1'],
            'safetyConcerns.*' => ['in:'.implode(',', array_keys(ParcelSettings::SAFETY_CONCERNS))],
            'safetyStage' => ['required', 'in:'.implode(',', array_keys(ParcelSettings::SAFETY_PHOTO_STAGES))],
            'safetyNote' => ['nullable', 'string', 'max:300'],
        ], [
            'safetyPhoto.required' => 'Take or choose a photo.',
            'safetyPhoto.image' => 'That file is not a photo.',
            'safetyPhoto.max' => 'The photo is too large (8 MB at most).',
            'safetyConcerns.required' => 'Tick what looks unusual.',
        ]);

        try {
            app(RecordParcelSafetyPhotoAction::class)->execute($orderId, $rider, $this->safetyPhoto, $this->safetyStage, $this->safetyConcerns, $this->safetyNote, $lat, $lng);
            $this->reset('safetyPhoto', 'safetyNote');
            $this->notice = 'Photo saved. Only the 1CallFix team can see it.';
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
        }
    }

    /** Pickup safety: refuse the parcel at the pickup point (live location required). */
    public function refuseParcel(int $orderId, ?float $lat = null, ?float $lng = null): void
    {
        $this->reset('notice', 'error');
        $rider = $this->rider();
        $this->ownOrder($orderId, $rider);

        $this->validate([
            'refuseReason' => ['required', 'in:'.implode(',', array_keys(ParcelSettings::REFUSAL_REASONS))],
            'refuseNote' => ['nullable', 'string', 'max:300'],
        ], ['refuseReason.required' => 'Choose why you are refusing the parcel.']);

        try {
            app(RefuseParcelAtPickupAction::class)->execute($orderId, $rider, $this->refuseReason, $this->refuseNote, $lat, $lng);
            $this->reset('refuseReason', 'refuseNote', 'safetyPhoto', 'safetyConcerns', 'safetyNote', 'pickupOtp');
            $this->notice = 'Parcel refused. The order is cancelled and the sender has been told. Leave safely; our team will follow up.';
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
        }
    }

    /**
     * Partner apps 2026-11-04: the Today card on Home — jobs finished today (parcel, shop, food, return pickups) and
     * what the rider earned on them, counted from midnight in the platform timezone.
     *
     * @return array{jobs:int, earned:float}
     */
    private function todayStats(\App\Models\FieldWorker $rider): array
    {
        $from = now(app(TimezoneResolver::class)->platformTimezone())->startOfDay()->utc();

        $parcels = \App\Models\ParcelOrder::where('assigned_worker_id', $rider->id)->where('status', 'delivered')
            ->where('delivered_at', '>=', $from)->with('commission')->get(['id']);
        $orderIds = \App\Models\MarketplaceOrder::where('assigned_worker_id', $rider->id)->where('order_type', 'delivery')
            ->where('status', 'completed')->where('completed_at', '>=', $from)->pluck('id');
        $shares = \App\Models\MarketplaceSettlement::whereIn('marketplace_order_id', $orderIds)->where('field_worker_id', $rider->id)
            ->get(['rider_share', 'rider_topup']);
        $returns = \App\Models\MarketplaceReturn::where('pickup_worker_id', $rider->id)
            ->where('pickup_status', \App\Models\MarketplaceReturn::PICKUP_DROPPED)->where('dropped_at', '>=', $from)->get(['pickup_fee']);

        return [
            'jobs' => $parcels->count() + $orderIds->count() + $returns->count(),
            'earned' => round((float) $parcels->sum(fn ($o) => (float) ($o->commission?->provider_commission ?? 0))
                + (float) $shares->sum(fn ($s) => (float) $s->rider_share + (float) $s->rider_topup)
                + (float) $returns->sum('pickup_fee'), 2),
        ];
    }

    public function render()
    {
        $offers = app(RiderOfferService::class);
        $dues = app(FieldWorkerCashDueService::class);
        $rider = $this->rider();
        $active = $this->parcelActiveFor($rider);
        $job = $offers->activeJob($rider);
        $shopJob = $offers->activeShopJob($rider);
        $pickups = app(\App\Services\Marketplace\MarketplaceReturnPickupService::class);
        $returnJob = $pickups->activeFor($rider);
        $live = ($rider->is_online && $active && ! $job && ! $shopJob && ! $returnJob) ? $offers->liveOffers($rider) : collect();
        // Rider app 2026-11-04: shop and food offers and return pickups are taken right here, next to parcels.
        $shopLive = ($rider->is_online && ! $job && ! $shopJob && ! $returnJob) ? $offers->liveShopOffers($rider) : collect();
        $openPickups = ($rider->is_online && ! $job && ! $shopJob && ! $returnJob) ? $pickups->openPickupsFor($rider) : collect();

        $this->dispatch('provider-alert-offers', count: $live->count() + $shopLive->count(),
            offers: array_merge($offers->alertSummaries($live), $offers->shopAlertSummaries($shopLive)));

        return view('livewire.rider.dashboard', [
            'rider' => $rider,
            'parcelActive' => $active,
            'job' => $job,
            'offers' => $live,
            'shopJob' => $shopJob,
            'shopOffers' => $shopLive,
            'returnJob' => $returnJob,
            'pickups' => $openPickups,
            // One figure for every kind of job: parcel cash dues + shop / food cash to hand back (as on Earnings).
            'duesOwed' => round($dues->outstanding($rider) + app(\App\Services\Marketplace\MarketplaceSettlementService::class)->outstanding('field_worker', $rider->id), 2),
            'anyDeliveryOn' => $active || app(\App\Services\ModuleActivationService::class)->isActive(\App\Support\Modules::COMMERCE, $this->riderScope($rider))
                || app(\App\Services\ModuleActivationService::class)->isActive(\App\Support\Modules::FOOD, $this->riderScope($rider)),
            'overCashLimit' => $dues->overCashLimit($rider, $this->riderScope($rider)),
            'locationFresh' => $rider->location_updated_at && $rider->location_updated_at->gt(now()->subMinutes(max(1, (int) Setting::get('provider.location_stale_after_minutes', '30')))),
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
            'waiting' => $job ? ParcelWaitingCharge::compute($job) : null,
            'contents' => ParcelSettings::CONTENTS,
            'safetyConcernOptions' => ParcelSettings::SAFETY_CONCERNS,
            'safetyStageOptions' => ParcelSettings::SAFETY_PHOTO_STAGES,
            'refusalReasons' => ParcelSettings::REFUSAL_REASONS,
            'safetyPhotoCount' => $job ? $job->safetyPhotos()->count() : 0,
            'refusalPay' => $job ? ParcelSettings::get('parcel.refusal_rider_pay', ParcelWaitingCharge::scope($job)) : null,
            'tz' => app(TimezoneResolver::class)->platformTimezone(),
            'today' => $this->todayStats($rider),
        ])->layout('components.layouts.rider', ['title' => 'Home']);
    }
}
