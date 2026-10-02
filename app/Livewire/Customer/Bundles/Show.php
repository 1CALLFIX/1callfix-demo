<?php

namespace App\Livewire\Customer\Bundles;

use App\Actions\CancelBookingBundleAction;
use App\Models\BookingBundle;
use App\Services\Payments\BookingBundlePaymentService;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One booking bundle: its child bookings, its status, and — when it was
 * checked out with an online method and is still unpaid — the Razorpay
 * hand-off (the same web pattern as wallet top-up; capture is the existing
 * /api/webhooks/razorpay handler). "Cancel bundle" goes through the tested
 * CancelBookingBundleAction (per-child FSM cancel + shared-payment refund).
 *
 * IDOR: the bundle must belong to the authenticated customer or this is a
 * 404 — the same rule CustomerOrderShow applies to a single booking.
 */
class Show extends Component
{
    #[Locked]
    public int $bundleId;

    /** Set from ?pay=1 (checkout redirect for an online method) — the view auto-opens Razorpay once. */
    public bool $autoPay = false;

    public string $notice = '';

    public string $error = '';

    public function mount(BookingBundle $bundle): void
    {
        abort_unless($bundle->customer_id === auth()->id(), 404);

        $this->bundleId = $bundle->id;
        $this->autoPay = request()->boolean('pay')
            && $bundle->payment_method !== 'wallet'
            && $bundle->payment_status !== 'paid';
    }

    public function payNow(BookingBundlePaymentService $payments): void
    {
        $bundle = $this->bundle();

        if ($bundle->payment_status === 'paid') {
            $this->notice = 'This bundle is already paid.';

            return;
        }

        try {
            $order = $payments->createOrder($bundle);
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->dispatch('bundle-pay-open', order: $order)->self();
    }

    /** Step 1 of cancelling: show which visits will be cancelled and which will stay (and why). Nothing is cancelled yet. */
    public ?array $cancelPreview = null;

    public function reviewCancel(CancelBookingBundleAction $action): void
    {
        $this->reset('error', 'notice');
        $this->bundle(); // ownership (404)
        $this->cancelPreview = $action->preview($this->bundleId);
    }

    public function dismissCancel(): void
    {
        $this->cancelPreview = null;
    }

    /** Step 2: confirm exactly the set the customer was shown. */
    public function cancelBundle(CancelBookingBundleAction $action): void
    {
        $this->bundle(); // ownership (404)

        try {
            $action->execute($this->bundleId, 'Cancelled by the customer from the web.', $this->cancelPreview['token'] ?? null);
            $this->cancelPreview = null;
            $this->notice = 'Your bundle has been cancelled. Any refund due has been processed to your wallet.';
        } catch (\App\Services\Cancellation\BundleCancelNeedsConfirmation $e) {
            $this->cancelPreview = $e->preview; // what changed since it was shown — the customer re-confirms
            $this->error = 'The visits changed while you were reviewing. Please check the list and confirm again.';
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    private function bundle(): BookingBundle
    {
        return BookingBundle::where('customer_id', auth()->id())
            ->with(['children.service.category', 'children.franchise:id,country_id', 'children.franchise.country:id,default_timezone'])
            ->findOrFail($this->bundleId);
    }

    public function render()
    {
        $bundle = $this->bundle();

        return view('livewire.customer.bundles.show', [
            'bundle' => $bundle,
            'children' => $bundle->children,
            'derivedStatus' => $bundle->derivedStatus(),
            'currencySymbol' => \App\Models\Setting::get('locale.currency_symbol', '₹'),
        ])->layout('components.layouts.customer', ['title' => 'Your bundle']);
    }
}
