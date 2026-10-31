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

    public bool $confirmingCancel = false;

    public string $cancelReason = '';

    public string $notice = '';

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

    public function render()
    {
        $order = $this->order();

        return view('livewire.customer.parcel.show', [
            'order' => $order,
            'cancelFee' => $order->canBeCancelledByCustomer() ? app(CustomerCancelParcelOrderAction::class)->fee($order) : 0.0,
            'live' => ! in_array($order->status, ['delivered', 'cancelled'], true),
            'canClaim' => app(\App\Services\Parcel\ParcelProtectionClaimService::class)->cannotClaim($order, auth()->id()) === null,
            'claims' => $order->protectionClaims()->latest('id')->get(),
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
        ])->layout('components.layouts.customer', ['title' => 'Parcel '.$order->code]);
    }
}
