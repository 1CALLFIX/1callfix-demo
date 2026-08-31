<?php

namespace App\Livewire\Customer\Orders;

use App\Actions\AdminCancelBookingAction;
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

    public function cancel(AdminCancelBookingAction $action): void
    {
        $this->reset('error', 'notice');
        $booking = $this->booking();

        if (in_array($booking->status, ['completed', 'cancelled'], true)) {
            $this->error = "This booking is already {$booking->status}.";
            $this->confirmingCancel = false;

            return;
        }

        try {
            $action->execute($booking->id, 'Cancelled by customer from the web app');
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
        }

        $this->confirmingCancel = false;
        $this->notice = 'Your booking has been cancelled.';
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
                'amount' => $booking->price_quoted,
                'gateway' => $gateway->identifier(),
                'status' => 'pending',
            ],
        );

        $this->dispatch('razorpay-open', order: $order, bookingCode: $booking->code);
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

        return view('livewire.customer.orders.show', [
            'booking' => $booking,
            'currencySymbol' => $currencySymbol,
            'existingReview' => $booking->review,
            'gatewayConfigured' => app(PaymentGateway::class)->isConfigured(),
            'capturedPaymentId' => $capturedPayment?->id,
            // The page re-polls itself while this is true (see the blade).
            'isInFlight' => in_array($booking->status, self::IN_FLIGHT_STATUSES, true),
            // True while dispatch is still hunting — drives the prominent
            // "finding a professional" panel.
            'isSearching' => in_array($booking->status, ['pending', 'searching_provider'], true)
                && $booking->status !== 'cancelled',
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
