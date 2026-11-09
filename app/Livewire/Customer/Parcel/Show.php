<?php

namespace App\Livewire\Customer\Parcel;

use App\Actions\CustomerCancelParcelOrderAction;
use App\Models\ParcelOrder;
use App\Models\Setting;
use App\Services\Parcel\ParcelPaymentService;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Customer parcel tracking: status, rider, the two OTPs (the sender gives the pickup OTP to the rider and
 * passes the delivery OTP to the recipient), price breakdown, "Pay now" for an unpaid online order, and
 * cancel while the parcel has not left the sender. Polls while the order is live. Stays reachable for the
 * order's owner even if parcel is later switched off on the Modules screen.
 */
class Show extends Component
{
    use WithFileUploads;

    public int $orderId;

    /** Stage 2: protection claim form (lost or damaged). */
    public bool $claiming = false;

    public string $claimReason = 'damaged';

    public string $claimDescription = '';

    public string $claimAmount = '';

    public array $claimPhotos = [];

    /** Stage 2: star rating of the rider after delivery. */
    public int $stars = 0;

    public string $ratingComment = '';

    public bool $confirmingCancel = false;

    public string $cancelReason = '';

    public string $notice = '';

    /** Set by the Razorpay success handler (?paid=1): wait for the bank's confirmation, never offer Pay again. */
    #[\Livewire\Attributes\Url(as: 'paid')]
    public bool $justPaid = false;

    /** NO PAYMENT, NO BOOKING (owner 2026-11-07): open / ended unpaid online order, or null for a real order. */
    private function attemptState(ParcelOrder $o): ?string
    {
        if ($o->payment_method !== 'online' || $o->payment_status !== 'pending') {
            return null;
        }

        return match ($o->status) {
            'pending' => 'open',
            'cancelled' => 'ended',
            default => null,
        };
    }

    /** "Don't send now": the unpaid parcel order ends quietly; nothing was booked. */
    public function leaveAttempt(\App\Actions\AdminCancelParcelOrderAction $action)
    {
        $order = $this->order();
        if ($this->attemptState($order) === 'open') {
            try {
                $action->execute($order->id, 'Customer left the payment — no order was made', 'customer', notifyCustomer: false);
            } catch (\RuntimeException $e) {
                report($e);
            }
        }

        return $this->redirectRoute('customer.parcels.create', navigate: true);
    }

    public string $error = '';

    public function mount(ParcelOrder $parcelOrder): void
    {
        abort_unless($parcelOrder->customer_id === auth()->id(), 404);
        $this->orderId = $parcelOrder->id;
    }

    private function order(): ParcelOrder
    {
        $order = ParcelOrder::with(['pickupAddress', 'dropoffAddress', 'assignedWorker.user', 'statusHistory', 'franchise', 'stops.address'])->findOrFail($this->orderId);
        abort_unless($order->customer_id === auth()->id(), 404);

        return $order;
    }

    public function pay(ParcelPaymentService $payments): void
    {
        $this->reset('error', 'notice');

        try {
            $checkout = $payments->startOnlinePayment($this->order());
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        } catch (\Throwable $e) {
            report($e);
            $this->error = "We couldn't start the payment. Please try again in a moment.";

            return;
        }

        $this->dispatch('razorpay-open', order: $checkout)->self();
    }

    public function cancel(CustomerCancelParcelOrderAction $action): void
    {
        $this->reset('error', 'notice');
        $this->validate(['cancelReason' => ['required', 'string', 'min:3', 'max:255']], ['cancelReason.required' => 'Tell us why you are cancelling.']);

        try {
            $action->execute($this->order(), auth()->user(), $this->cancelReason);
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->confirmingCancel = false;
        $this->notice = 'Your parcel order is cancelled. Any refund is on its way.';
    }

    /** Stage 2: report a protected parcel lost or damaged. The payout is decided by the 1CallFix team (approval model). */
    public function fileClaim(\App\Services\Parcel\ParcelProtectionClaimService $claims): void
    {
        $this->reset('error', 'notice');
        $this->validate([
            'claimReason' => ['required', 'in:'.implode(',', array_keys(\App\Models\ParcelProtectionClaim::REASONS))],
            'claimDescription' => ['required', 'string', 'min:5', 'max:1000'],
            'claimAmount' => ['required', 'numeric', 'min:1'],
            'claimPhotos' => ['array', 'max:'.\App\Services\Parcel\ParcelProtectionClaimService::PHOTOS_MAX],
            'claimPhotos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ], ['claimDescription.required' => 'Tell us what happened.', 'claimAmount.required' => 'Enter the amount you are claiming.']);

        try {
            $claims->file($this->order(), auth()->user(), $this->claimReason, $this->claimDescription, (float) $this->claimAmount, $this->claimPhotos);
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->reset('claiming', 'claimDescription', 'claimAmount', 'claimPhotos');
        $this->notice = 'Claim sent. Our team will check it and update you here.';
    }

    /** Stage 2: the sender rates the rider once, after delivery. */
    public function rate(\App\Services\Parcel\ParcelRatingService $ratings): void
    {
        $this->reset('error', 'notice');
        try {
            $ratings->rate($this->order(), auth()->user(), $this->stars, $this->ratingComment);
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }
        $this->notice = 'Thank you for rating your rider.';
    }

    /**
     * Stage 2: "Rider is 2.4 km away" - from the rider's last location to where they are heading next. Only while
     * the rider is on the job and their location is fresh (10 min); null otherwise (the page then shows nothing).
     */
    private function riderAway(ParcelOrder $order): ?array
    {
        $w = $order->assignedWorker;
        if (! $w || ! in_array($order->status, ParcelOrder::ACTIVE_RIDER_STATUSES, true) || $w->current_lat === null || ! $w->location_updated_at) {
            return null;
        }
        if ($w->location_updated_at->lt(now()->subMinutes(10))) {
            return null;
        }
        $before = in_array($order->status, ['assigned', 'worker_en_route_pickup'], true);
        $returning = $order->isReturning();
        if ($before || $returning) {
            $target = $order->pickupAddress;
        } elseif ($order->drop_delivered_at) {
            $target = $order->stops->firstWhere('delivered_at', null)?->address;
        } else {
            $target = $order->dropoffAddress;
        }
        $km = $target && $target->lat !== null
            ? app(\App\Services\DispatchService::class)->haversineKm((float) $w->current_lat, (float) $w->current_lng, (float) $target->lat, (float) $target->lng)
            : null;

        return [
            'km' => $km,
            'to' => $before ? 'pickup' : ($returning ? 'you' : 'the drop'),
            'ago' => $w->location_updated_at->diffForHumans(short: true),
            'lat' => $w->current_lat,
            'lng' => $w->current_lng,
        ];
    }

    public function render()
    {
        $order = $this->order();

        return view('livewire.customer.parcel.show', [
            'order' => $order,
            'attempt' => $this->attemptState($order),
            'cancelFee' => $order->canBeCancelledByCustomer() ? app(CustomerCancelParcelOrderAction::class)->fee($order) : 0.0,
            'live' => ! in_array($order->status, ['delivered', 'cancelled'], true),
            'canClaim' => app(\App\Services\Parcel\ParcelProtectionClaimService::class)->cannotClaim($order, auth()->id()) === null,
            'claims' => $order->protectionClaims()->latest('id')->get(),
            'riderAway' => $this->riderAway($order),
            'showChat' => $order->assigned_worker_id && (app(\App\Services\Parcel\ParcelChatService::class)->isOpen($order) || $order->messages()->exists()),
            'myRating' => $order->rating,
            'canRate' => app(\App\Services\Parcel\ParcelRatingService::class)->cannotRate($order, auth()->id()) === null,
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ])->layout('components.layouts.customer', ['title' => 'Parcel '.$order->code]);
    }
}
