<?php

namespace App\Livewire\Customer\Orders;

use App\Actions\CustomerCancelBookingAction;
use App\Actions\DisputeInterimDeclarationAction;
use App\Actions\FlagProviderLeftAction;
use App\Actions\RespondToExtraWorkAction;
use App\Contracts\PaymentGateway;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Review;
use App\Models\Setting;
use App\Services\ReviewService;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Phase E6 — one booking in full for its owner: live status, the assigned
 * professional, the customer's own start/completion OTP codes, payment
 * state, invoice, cancellation and, once completed, a review + "book
 * again".
 *
 * ── Nothing here is a second implementation ───────────────────────────────
 *   cancel    -> App\Actions\AdminCancelBookingAction (the same engine the
 *                admin panel and API\BookingController::cancel() call; the
 *                ONLY thing added is the ownership check an admin doesn't
 *                need)
 *   pay       -> the App\Contracts\PaymentGateway binding's createOrder(),
 *                exactly as API\PaymentController::createOrder() does; the
 *                webhook stays the source of truth for capture
 *   review    -> App\Services\ReviewService::submit() (ownership, "completed
 *                only", one-per-booking — all enforced in the service)
 *   OTPs      -> DISPLAYED only. The customer reads them to the professional;
 *                verification is the provider-side E5 flow and is untouched.
 *
 * IDOR: mount() 404s (never 403 — the same information-hiding convention
 * every customer endpoint in this codebase uses) on any booking whose
 * customer_id is not the authed user, and #[Locked] pins the id.
 */
class Show extends Component
{
    #[Locked]
    public int $bookingId;

    // review sub-form
    public int $rating = 0;

    public string $comment = '';

    public string $error = '';

    public string $notice = '';

    public bool $confirmingCancel = false;

    /** REF 1CF-CANCEL-POLICY-001 — the signed quote the customer is confirming; empty when no charge applies. */
    public string $quoteToken = '';

    public bool $disputing = false;

    public string $disputeNote = '';

    /** A2 — post-payment pricing dispute */
    public bool $pricingDisputing = false;

    public string $pricingDisputeReason = '';

    public function mount(Booking $booking): void
    {
        abort_unless($booking->customer_id === auth()->id(), 404);

        $this->bookingId = $booking->id;
    }

    /**
     * Statuses that are still "in flight" — dispatch is running or the job
     * is under way. The view polls itself only while the booking is one of
     * these; a completed or cancelled booking never changes again, so it
     * stops polling rather than hammering the server forever.
     */
    private const IN_FLIGHT_STATUSES = [
        'pending', 'searching_provider', 'assigned', 'provider_en_route', 'in_progress', 'on_hold',
    ];

    private function booking(): Booking
    {
        $booking = Booking::with([
            'service.category', 'address', 'provider.user', 'assignedWorker.user',
            'statusHistory' => fn ($q) => $q->orderBy('changed_at')->orderBy('id'),
            'dispatchAttempts',
            'review', 'payment', 'bundle.payment',
            'franchise:id,country_id', 'franchise.country:id,default_timezone',
        ])->findOrFail($this->bookingId);

        abort_unless($booking->customer_id === auth()->id(), 404);

        return $booking;
    }

    /** REF 1CF-EXTRAWORK-001 — approve or decline extra work the professional asked for. */
    public function respondToExtraWork(int $itemId, bool $approved, RespondToExtraWorkAction $action): void
    {
        $this->reset('error', 'notice');
        $this->booking(); // ownership check (404)

        try {
            $action->execute($itemId, auth()->id(), $approved);
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->notice = $approved
            ? 'Approved. The professional will carry on; the extra amount is added to your final bill.'
            : 'Declined. The job continues at the original price.';
    }

    /** REF 1CF-JOURNEY-001 — "my professional left before finishing". Pauses the job and alerts the operator. */
    public function reportProfessionalLeft(FlagProviderLeftAction $action): void
    {
        $this->reset('error', 'notice');
        $booking = $this->booking();

        try {
            $action->execute($booking->id, 'customer', auth()->id());
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->notice = 'Thanks for telling us. We have paused the job and our team will send another professional or cancel it with no fee.';
    }

    /** REF 1CF-CANCEL-POLICY-001 — show the exact amount (or why it is locked) before anything is cancelled. */
    public function openCancel(CustomerCancelBookingAction $action): void
    {
        $this->reset('error', 'notice');
        $quote = $action->quote($this->booking());

        $this->quoteToken = (string) ($quote['token'] ?? '');
        $this->confirmingCancel = true;
    }

    public function cancel(CustomerCancelBookingAction $action): void
    {
        $this->reset('error', 'notice');
        $this->booking(); // ownership check (404)

        try {
            $result = $action->execute($this->bookingId, auth()->id(), 'Cancelled by customer from the web app', $this->quoteToken !== '' ? $this->quoteToken : null);
        } catch (\App\Services\Cancellation\CancellationBlockedException $e) {
            $this->error = $e->getMessage();
            $this->confirmingCancel = false;

            return;
        } catch (\App\Services\Cancellation\CancellationQuoteChangedException $e) {
            // The figures moved since the customer looked: show the new amount and ask again.
            $this->error = $e->getMessage();
            $this->quoteToken = (string) ($e->quote['token'] ?? '');

            return;
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
            $this->confirmingCancel = false;

            return;
        }

        $this->confirmingCancel = false;
        $this->quoteToken = '';

        match ($result['outcome']) {
            'payment_required' => $this->openChargeCheckout($result),
            'awaiting_admin' => $this->notice = 'Your cancellation request has been received. Our team will review the charge and confirm shortly.',
            default => $this->notice = $result['charge'] > 0
                ? 'Your booking has been cancelled. A charge of '.number_format($result['charge'], 2).' applies for the work already done.'
                : 'Your booking has been cancelled.',
        };
    }

    private function openChargeCheckout(array $result): void
    {
        $this->notice = 'Pay the cancellation charge to complete the cancellation. The booking stays as it is until you pay.';
        $this->dispatch('razorpay-open', order: $result['order'], bookingCode: $result['booking']->code)->self();
    }

    /** Re-opens checkout for a cancellation that is waiting on the customer's payment (the "payment link" on the page). */
    public function payCancellationCharge(CustomerCancelBookingAction $action): void
    {
        $booking = $this->booking();

        // A charge the professional raised when THEY cancelled: the booking is already cancelled, only the payment is outstanding.
        if ($booking->status === 'cancelled') {
            $request = $booking->cancellationRequests()->whereIn('status', ['awaiting_payment', 'awaiting_admin'])->latest('id')->first();
            if (! $request) {
                return;
            }
            $this->reset('error', 'notice');
            $result = $action->payOutstandingCharge($request->id, auth()->id());
            if ($result['outcome'] === 'payment_required' && $result['order']) {
                $this->openChargeCheckout($result);
            } else {
                $this->notice = $result['outcome'] === 'awaiting_admin' ? 'Our team will contact you about this charge.' : 'Charge paid — thank you.';
            }

            return;
        }

        $this->openCancel($action);
        $this->cancel($action);
    }

    /** REF 1CF-CANCEL-POLICY-001 — accept / reject the professional's in-app quote. */
    public function respondToQuote(int $quoteId, bool $accept, \App\Actions\RespondToBookingQuoteAction $action): void
    {
        $this->reset('error', 'notice');
        $this->booking(); // ownership check (404)

        try {
            $action->execute($quoteId, auth()->id(), $accept);
            $this->notice = $accept ? 'Quote accepted — the professional will carry on.' : 'Quote declined.';
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            abort(404);
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function raisePricingDispute(\App\Services\BookingDisputeService $service): void
    {
        $this->reset('error', 'notice');
        $this->booking(); // ownership check (404)

        try {
            $service->raise($this->bookingId, auth()->id(), $this->pricingDisputeReason);
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->reset('pricingDisputing', 'pricingDisputeReason');
        $this->notice = 'Thanks. Our team will review the price and talk to you and the professional. You will be told the outcome.';
    }

    public function disputeProgress(DisputeInterimDeclarationAction $action): void
    {
        $this->reset('error', 'notice');
        $this->booking(); // ownership check (404)

        try {
            $action->execute($this->bookingId, auth()->id(), $this->disputeNote);
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->disputing = false;
        $this->disputeNote = '';
        $this->notice = 'Thanks. Our team will review the progress figures; you will be told the outcome. Cancellation waits for that review.';
    }

    /**
     * Open a Razorpay order for a still-unpaid online booking — the same
     * call API\PaymentController::createOrder() makes. Only offered when the
     * gateway is actually configured; the webhook remains authoritative for
     * marking the payment captured.
     */
    public function startPayment(PaymentGateway $gateway): void
    {
        $this->reset('error', 'notice');
        $booking = $this->booking();

        if ($booking->payment_status === 'paid') {
            $this->notice = 'This booking is already paid.';

            return;
        }

        if (! $gateway->isConfigured()) {
            $this->error = 'Online payment is not available in this environment.';

            return;
        }

        $scope = array_filter(['zone_id' => $booking->zone_id, 'franchise_id' => $booking->franchise_id]);
        if (Setting::get('payment.online_enabled', '1', $scope) !== '1') {
            $this->error = 'Online payments are currently disabled for your area.';

            return;
        }

        $order = $gateway->createOrder($booking);

        Payment::firstOrCreate(
            ['gateway_order_id' => $order['razorpay_order_id']],
            [
                'booking_id' => $booking->id,
                'amount' => $booking->amountPayable(),
                'gateway' => $gateway->identifier(),
                'status' => 'pending',
            ],
        );

        $this->dispatch('razorpay-open', order: $order, bookingCode: $booking->code)->self();
    }

    public function submitReview(ReviewService $reviews): void
    {
        $this->reset('error', 'notice');

        $this->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ], ['rating.min' => 'Choose a star rating.', 'rating.required' => 'Choose a star rating.']);

        try {
            $reviews->submit($this->booking(), auth()->user(), $this->rating, $this->comment ?: null);
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->notice = 'Thanks for the review!';
        $this->reset('rating', 'comment');
    }

    public function render()
    {
        $booking = $this->booking();
        $currencySymbol = Setting::get('locale.currency_symbol', '₹');

        $capturedPayment = ($booking->payment && $booking->payment->status === 'captured')
            ? $booking->payment
            : (($booking->bundle?->payment && $booking->bundle->payment->status === 'captured') ? $booking->bundle->payment : null);

        // Dispatch context — all real rows from `dispatch_attempts`, nothing
        // invented. `contactedCount` is how many distinct professionals have
        // been offered this booking so far (drives the "we're looking" copy);
        // `providerDistanceKm` is the Haversine distance DispatchService
        // recorded on the offer the assigned professional accepted.
        $contactedCount = $booking->dispatchAttempts->pluck('provider_id')->filter()->unique()->count();
        $acceptedAttempt = $booking->dispatchAttempts->firstWhere('status', 'accepted');
        $providerDistanceKm = $acceptedAttempt && $acceptedAttempt->distance_km !== null
            ? (float) $acceptedAttempt->distance_km
            : null;

        $cancelQuote = app(CustomerCancelBookingAction::class)->quote($booking);

        return view('livewire.customer.orders.show', [
            'booking' => $booking,
            // REF 1CF-CANCEL-POLICY-001
            'cancelQuote' => $cancelQuote,
            'policyLines' => app(\App\Services\Cancellation\CancellationPolicy::class)->policyLines($booking),
            'pendingQuote' => $booking->quotes()->where('status', 'sent')->latest('id')->first(),
            'documents' => app(\App\Services\Documents\CancellationDocumentService::class),
            'pendingCancelRequest' => $booking->cancellationRequests()->whereIn('status', ['awaiting_payment', 'awaiting_admin'])->latest('id')->first(),
            'canDisputeProgress' => $booking->status === 'on_hold' && $booking->hold_reason === 'awaiting_spares' && $booking->interim_declared_at !== null && $booking->interim_dispute_status !== 'open'
                && $booking->interim_declared_at->copy()->addHours(max(1, (int) \App\Services\Cancellation\PolicySettings::current('cancellation.dispute_window_hours')))->isFuture(),
            'pricingDispute' => \App\Models\BookingDispute::where('booking_id', $booking->id)->latest('id')->first(),
            'canRaisePricingDispute' => app(\App\Services\BookingDisputeService::class)->cannotRaise($booking, (int) auth()->id()) === null,
            'currencySymbol' => $currencySymbol,
            'existingReview' => $booking->review,
            'gatewayConfigured' => app(PaymentGateway::class)->isConfigured(),
            // C3: seconds left to pay a coupon booking before the sweep cancels it and releases the coupon (null = no hold).
            'holdSecondsLeft' => ($expires = \App\Services\Coupons\CouponHoldSweepService::expiresAt($booking))
                ? max(0, (int) ceil(now()->diffInSeconds($expires, false)))
                : null,
            'capturedPaymentId' => $capturedPayment?->id,
            // The page re-polls itself while this is true (see the blade).
            'isInFlight' => in_array($booking->status, self::IN_FLIGHT_STATUSES, true),
            // True while dispatch is still hunting — drives the prominent
            // "finding a professional" panel.
            'isSearching' => in_array($booking->status, ['pending', 'searching_provider'], true)
                && $booking->status !== 'cancelled',
            'pendingExtra' => $booking->status === 'on_hold' ? $booking->extraItems()->where('status', 'pending_approval')->latest('id')->first() : null,
            'contactedCount' => $contactedCount,
            'providerDistanceKm' => $providerDistanceKm,
            // Both codes belong to this customer (E5 sends them by SMS on
            // acceptance). Shown here so they can be read to the professional;
            // start_otp is NULLed by E5 once the job has been started.
            'showStartOtp' => in_array($booking->status, ['assigned', 'provider_en_route'], true) && ! empty($booking->start_otp),
            'showCompletionOtp' => in_array($booking->status, ['assigned', 'provider_en_route', 'in_progress'], true) && ! empty($booking->completion_otp),
        ])->layout('components.layouts.customer', ['title' => 'Booking '.$booking->code]);
    }
}
