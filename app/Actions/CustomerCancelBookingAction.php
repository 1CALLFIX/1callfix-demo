<?php

namespace App\Actions;

use App\Contracts\PaymentGateway;
use App\Models\Booking;
use App\Models\BookingCancellationRequest;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Services\AdminOpsAlertService;
use App\Services\Cancellation\CancellationBlockedException;
use App\Services\Cancellation\CancellationPolicy;
use App\Services\Cancellation\CancellationQuoteChangedException;
use App\Services\Cancellation\CancellationQuoteToken;
use App\Services\Cancellation\PolicySettings;
use App\Services\ProviderReliabilityService;
use App\Services\CommissionService;
use App\Services\WalletService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * REF 1CF-CANCEL-POLICY-001 — the CUSTOMER-facing cancellation of a Service booking (web, API, bundle child).
 * See docs/CANCELLATION_POLICY_DESIGN.md. Every rule lives in CancellationPolicy; the cancel itself is still
 * AdminCancelBookingAction (FSM guard, refund, entitlements, notifications) — admin keeps its own unrestricted path.
 *
 *   quote()    what the customer would pay / get back, plus a signed token to confirm it
 *   execute()  re-evaluates under the booking row lock, then either
 *                - cancels (charge 0, or deducted from an already-paid booking), or
 *                - settles first (cash/unpaid): wallet debit, or a gateway order the webhook completes, or
 *                  hands the case to an admin when no online payment is possible.
 *              Double submits are idempotent.
 */
class CustomerCancelBookingAction
{
    public const UNPAID_DUE_DAYS = 7;

    public function __construct(
        private AdminCancelBookingAction $cancel,
        private CancellationPolicy $policy,
        private CommissionService $commissions,
        private WalletService $wallet,
        private PaymentGateway $gateway,
    ) {
    }

    /** @return array{allowed: bool, code: string, message: string, charge: float, breakdown: ?array, unlocks_at: mixed, free: bool, requires_payment: bool, refund: float, token: ?string} */
    public function quote(Booking $booking): array
    {
        $decision = $this->policy->evaluate($booking);
        $charge = $decision['charge'];
        $prepaid = $this->isPrepaid($booking);

        return $decision + [
            'requires_payment' => $decision['allowed'] && $charge > 0 && ! $prepaid,
            'refund' => $decision['allowed'] && $prepaid ? round(max($booking->amountPayable() - $charge, 0), 2) : 0.0,
            'token' => $decision['allowed'] && $charge > 0 ? CancellationQuoteToken::issue($booking, $charge) : null,
        ];
    }

    /**
     * @return array{outcome: string, booking: Booking, charge: float, request: ?BookingCancellationRequest, order: ?array, already: bool}
     *   outcome: cancelled | payment_required | awaiting_admin
     *
     * @throws ModelNotFoundException when the booking is not this customer's
     * @throws CancellationBlockedException
     * @throws CancellationQuoteChangedException
     */
    public function execute(int $bookingId, int $customerId, string $reason, ?string $quoteToken = null, bool $reconcileBundle = true, bool $quoteWaived = false): array
    {
        $booking = Booking::where('customer_id', $customerId)->findOrFail($bookingId);

        if ($booking->status === 'cancelled' && $booking->cancelled_by_role === 'customer') {
            return $this->result('cancelled', $booking, (float) $booking->cancellation_fee, already: true);
        }

        $quote = $this->quote($booking);
        if (! $quote['allowed']) {
            throw new CancellationBlockedException($quote);
        }

        $charge = $quote['charge'];
        if ($charge > 0 && ! $quoteWaived && ! CancellationQuoteToken::valid($booking, $charge, $quoteToken)) {
            throw new CancellationQuoteChangedException($quote);
        }

        if ($quote['requires_payment']) {
            return $this->settleFirst($booking, $customerId, $reason, $charge, $quote);
        }

        return $this->finalise($booking->id, $customerId, $reason, mode: 'quoted', quoteToken: $quoteToken, reconcileBundle: $reconcileBundle, quoteWaived: $quoteWaived);
    }

    /**
     * Called by the Razorpay webhook once the separate cancellation-charge payment is captured. Idempotent.
     * If the job moved on meanwhile (resumed, cancelled elsewhere) the customer's money is refunded in full.
     */
    public function completeAfterChargePayment(Payment $payment): void
    {
        $request = BookingCancellationRequest::where('payment_id', $payment->id)->first();
        if (! $request) {
            return;
        }

        if (! in_array($request->status, ['awaiting_payment', 'awaiting_admin'], true)) {
            if ($request->status !== 'completed') {
                $this->refundPayment($payment, (float) $payment->amount, 'Cancellation charge no longer applicable');
            }

            return;
        }

        $booking = Booking::find($request->booking_id);

        // A charge the professional raised when they cancelled: the booking is already cancelled, so paying it just settles it.
        if ($booking->status === 'cancelled' && $booking->cancelled_by_role === 'provider') {
            $this->settleProviderCancelCharge($request, $booking);

            return;
        }

        try {
            $cancelled = $this->finalise($booking->id, $booking->customer_id, (string) $request->reason, mode: 'settled', settledCharge: (float) $request->total_charge, request: $request, payment: $payment);
        } catch (CancellationBlockedException|\RuntimeException $e) {
            // Job resumed / already terminal while the customer was paying: give the money back, drop the request.
            $this->refundPayment($payment, (float) $payment->amount, 'Cancellation not applicable — job continued');
            $request->update(['status' => 'superseded', 'resolution_note' => 'Job state changed before payment completed; charge refunded.']);
            Log::info("Cancellation charge payment [{$payment->id}] refunded: booking [{$booking->id}] no longer cancellable.");

            return;
        }

        unset($cancelled);
    }

    // ------------------------------------------------------------------ internals

    /** Cash / unpaid: the charge must be settled BEFORE the cancellation completes. */
    private function settleFirst(Booking $booking, int $customerId, string $reason, float $charge, array $quote): array
    {
        $customer = User::findOrFail($customerId);

        $request = DB::transaction(function () use ($booking, $customerId, $reason, $charge, $quote) {
            Booking::lockForUpdate()->findOrFail($booking->id);

            $open = BookingCancellationRequest::where('booking_id', $booking->id)->whereIn('status', ['awaiting_payment', 'awaiting_admin'])->latest('id')->first();
            if ($open && abs((float) $open->total_charge - $charge) < 0.005) {
                return $open; // double submit: reuse
            }
            if ($open) {
                $open->update(['status' => 'superseded', 'resolution_note' => 'Replaced by a newer quote.']);
            }

            return BookingCancellationRequest::create([
                'booking_id' => $booking->id,
                'requested_by' => $customerId,
                'status' => 'awaiting_payment',
                'total_charge' => $charge,
                'amount_due' => $charge,
                'basis' => $quote['breakdown'] ?? ['code' => $quote['code']],
                'reason' => $reason,
                'due_by' => now()->addDays(max(1, (int) PolicySettings::get($booking, 'cancellation.unpaid_flag_days'))),
            ]);
        });

        // 1) wallet, when it can cover the charge
        if (! $this->wallet->isFrozen($customer) && $this->wallet->balance($customer) >= $charge) {
            $ref = "booking:{$booking->id}:cancel-charge:{$request->id}";
            $this->debitCharge($customer, $booking, $request, $charge);

            try {
                $result = $this->finalise($booking->id, $customerId, $reason, mode: 'settled', settledCharge: $charge, request: $request);

                // The live policy may have priced it lower than the quote: hand the difference back to the wallet.
                if ($result['charge'] < $charge) {
                    $this->wallet->credit($customer, round($charge - $result['charge'], 2), "Cancellation charge adjusted for booking {$booking->code}", "{$ref}:difference");
                }

                return $result;
            } catch (\Throwable $e) {
                $this->wallet->credit($customer, $charge, "Cancellation charge reversed for booking {$booking->code}", "{$ref}:reversal");
                $request->update(['status' => 'superseded', 'resolution_note' => 'Cancellation failed after wallet debit; reversed.']);

                throw $e;
            }
        }

        return $this->collectOnline($booking, $customerId, $customer, $request, $charge);
    }


    /** 2) an online payment the webhook completes (or hand the case to an admin when online payment is not possible). */
    private function collectOnline(Booking $booking, int $customerId, User $customer, BookingCancellationRequest $request, float $charge): array
    {
        $online = Setting::get('payment.online_enabled', '1', ['zone_id' => $booking->zone_id, 'franchise_id' => $booking->franchise_id]) === '1';
        if (! $online || ! $this->gateway->isConfigured()) {
            $request->update(['status' => 'awaiting_admin', 'flagged_at' => now()]);
            $this->alert('cancel_charge_unpaid', $booking);

            return $this->result('awaiting_admin', $booking->fresh(), $charge, request: $request);
        }

        if ($request->payment_id && ($existing = Payment::find($request->payment_id)) && $existing->status === 'pending') {
            return $this->result('payment_required', $booking, $charge, request: $request, order: $this->orderPayload($existing));
        }

        $order = $this->gateway->createRawOrder($charge, "cancel-{$booking->code}-{$request->id}", [
            'purpose' => 'cancellation_fee', 'booking_id' => $booking->id, 'request_id' => $request->id,
        ]);

        $payment = Payment::create([
            'booking_id' => $booking->id,
            'user_id' => $customerId,
            'purpose' => 'cancellation_fee',
            'amount' => $charge,
            'gateway' => $this->gateway->identifier(),
            'gateway_order_id' => $order['razorpay_order_id'],
            'status' => 'pending',
        ]);
        $request->update(['payment_id' => $payment->id]);

        try {
            $customer->notify(new \App\Notifications\BookingStatusNotification(
                'cancel_payment_due', $booking,
                \App\Notifications\Support\ChannelResolver::resolve(array_filter(['zone_id' => $booking->zone_id, 'franchise_id' => $booking->franchise_id]))
            ));
        } catch (\Throwable $e) {
            Log::error("Failed to send cancellation-charge payment notice for booking [{$booking->id}]: ".$e->getMessage());
        }

        return $this->result('payment_required', $booking, $charge, request: $request, order: [
            'payment_id' => $payment->id,
            'razorpay_order_id' => $order['razorpay_order_id'],
            'razorpay_key_id' => $order['key_id'],
            'amount' => $order['amount'],
            'currency' => $order['currency'],
        ]);
    }

    /**
     * The customer settles a charge the professional raised when THEY cancelled (no-show / quote rejected): wallet first,
     * else a Razorpay order the webhook completes. Idempotent — a settled request is returned as-is.
     *
     * @return array{outcome: string, booking: Booking, charge: float, request: ?BookingCancellationRequest, order: ?array, already: bool}
     *
     * @throws ModelNotFoundException when the request is not on this customer's booking
     */
    public function payOutstandingCharge(int $requestId, int $customerId): array
    {
        $request = BookingCancellationRequest::findOrFail($requestId);
        $booking = Booking::where('customer_id', $customerId)->findOrFail($request->booking_id);
        $charge = (float) $request->total_charge;

        if (in_array($request->status, ['completed', 'waived', 'superseded'], true)) {
            return $this->result($request->status === 'completed' ? 'cancelled' : $request->status, $booking, $charge, request: $request, already: true);
        }

        $customer = User::findOrFail($customerId);

        if (! $this->wallet->isFrozen($customer) && $this->wallet->balance($customer) >= $charge) {
            $this->debitCharge($customer, $booking, $request, $charge);
            $this->settleProviderCancelCharge($request, $booking);

            return $this->result('cancelled', $booking->fresh(), $charge, request: $request->fresh());
        }

        return $this->collectOnline($booking, $customerId, $customer, $request, $charge);
    }

    /**
     * The ONE wallet debit for a cancellation charge, through WalletService (row-locked, ledgered; the `wallet_transactions.ref`
     * is DB-unique). Idempotent: if this request's charge was already debited (e.g. the process stopped before the request
     * was closed), the existing debit stands and nothing is taken twice. Source label: `cancel_charge` (WalletSourceLabel).
     */
    private function debitCharge(User $customer, Booking $booking, BookingCancellationRequest $request, float $charge): void
    {
        $ref = "booking:{$booking->id}:cancel-charge:{$request->id}";

        if (\App\Models\WalletTransaction::where('ref', $ref)->exists()) {
            return;
        }

        $this->wallet->debit($customer, $charge, "Cancellation charge for booking {$booking->code}", $ref);
    }

    /** A provider-raised charge has been collected: close the request (once) and pay the professional their share. */
    private function settleProviderCancelCharge(BookingCancellationRequest $request, Booking $booking): void
    {
        $claimed = DB::transaction(function () use ($request) {
            $locked = BookingCancellationRequest::lockForUpdate()->find($request->id);
            if (! in_array($locked->status, ['awaiting_payment', 'awaiting_admin'], true)) {
                return false;
            }
            $locked->update(['status' => 'completed']);

            return true;
        });

        if ($claimed) {
            $this->payProvider($booking, (float) $request->total_charge);
        }
    }

    /**
     * The one place the cancel is actually performed. The policy is evaluated AGAIN inside AdminCancelBookingAction's
     * row lock (feeResolver), so nothing that changed since the quote can be slipped past.
     *
     * @param  'quoted'|'settled'  $mode  quoted: the customer confirmed `quoteToken`; settled: `settledCharge` is already collected
     */
    private function finalise(int $bookingId, int $customerId, string $reason, string $mode, ?string $quoteToken = null, ?float $settledCharge = null, ?BookingCancellationRequest $request = null, ?Payment $payment = null, bool $reconcileBundle = true, bool $quoteWaived = false): array
    {
        $appliedCharge = 0.0;
        $appliedCode = null;

        $resolver = function (Booking $locked) use ($mode, $quoteToken, $settledCharge, &$appliedCharge, &$appliedCode, $customerId, $quoteWaived) {
            if ($locked->customer_id !== $customerId) {
                throw new ModelNotFoundException('Booking not found.');
            }

            $decision = $this->policy->evaluate($locked);
            if (! $decision['allowed']) {
                throw new CancellationBlockedException($decision);
            }

            $charge = $decision['charge'];

            if ($mode === 'quoted') {
                if ($charge > 0 && ! $quoteWaived && ! CancellationQuoteToken::valid($locked, $charge, $quoteToken)) {
                    throw new CancellationQuoteChangedException($this->quote($locked));
                }
            } else {
                // Already collected: honour the quote, but never keep more than the policy now allows.
                $charge = round(min((float) $settledCharge, $charge), 2);
            }

            $appliedCharge = $charge;
            $appliedCode = $decision['code'];

            return [$charge, ($decision['breakdown'] ?? []) + ['code' => $decision['code'], 'mode' => $mode, 'charge' => $charge]];
        };

        try {
            $cancelled = $this->cancel->execute($bookingId, $reason, reconcileBundle: $reconcileBundle, feeResolver: $resolver, cancelledByRole: 'customer');
        } catch (CancellationBlockedException|CancellationQuoteChangedException|ModelNotFoundException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            // A concurrent identical submit won the row lock first: that is a success, not an error.
            $fresh = Booking::find($bookingId);
            if ($fresh && $fresh->status === 'cancelled' && $fresh->cancelled_by_role === 'customer') {
                return $this->result('cancelled', $fresh, (float) $fresh->cancellation_fee, already: true);
            }

            throw $e;
        }

        if ($request) {
            $request->update(['status' => 'completed']);
        }

        // A settled charge may have been reduced by the live policy: hand the difference back.
        if ($payment && $mode === 'settled' && $appliedCharge < (float) $payment->amount) {
            $this->refundPayment($payment, round((float) $payment->amount - $appliedCharge, 2), 'Cancellation charge reduced');
        }

        // The professional kept the customer waiting: their reliability score drops (once per booking).
        if ($appliedCode === 'provider_late' && $cancelled->provider) {
            app(ProviderReliabilityService::class)->penalise($cancelled->provider, $cancelled, 'provider_late', max(0, (int) PolicySettings::current('cancellation.reliability_penalty_points')), 'Customer cancelled: professional late or no-show');
        }

        $this->payProvider($cancelled, $appliedCharge);

        return $this->result('cancelled', $cancelled, $appliedCharge);
    }

    /** Pay the professional their share of the collected charge. Never undoes the cancellation; the sweep retries a failure. */
    public function payProvider(Booking $cancelled, float $charge): void
    {
        // The 'assigned, not yet travelling' fee goes to the platform only — the professional receives nothing for it.
        if ($charge <= 0 || ! $cancelled->provider_id || ($cancelled->cancellation_fee_basis['code'] ?? null) === 'assigned') {
            return;
        }

        try {
            $this->commissions->applyForCancelledBooking($cancelled, $charge);
        } catch (\Throwable $e) {
            Log::error("Interim-work payout failed for cancelled booking [{$cancelled->id}]: ".$e->getMessage());
            $this->alert('cancel_payout_failed', $cancelled);
        }
    }

    private function refundPayment(Payment $payment, float $amount, string $note): void
    {
        if ($amount <= 0) {
            return;
        }

        try {
            if ($payment->gateway_payment_id) {
                $this->gateway->refund($payment->gateway_payment_id, $amount, $note);
            }
            $payment->refunded_amount = round((float) $payment->refunded_amount + $amount, 2);
            if ($payment->refunded_amount >= (float) $payment->amount) {
                $payment->status = 'refunded';
            }
            $payment->save();
        } catch (\Throwable $e) {
            Log::error("Refund of cancellation-charge payment [{$payment->id}] failed: ".$e->getMessage());
        }
    }

    private function isPrepaid(Booking $booking): bool
    {
        return $booking->payment_status === 'paid';
    }

    private function orderPayload(Payment $payment): array
    {
        return [
            'payment_id' => $payment->id,
            'razorpay_order_id' => $payment->gateway_order_id,
            'razorpay_key_id' => $this->gateway->checkoutKeyId(),
            'amount' => (int) round(((float) $payment->amount) * 100),
            'currency' => 'INR',
        ];
    }

    private function alert(string $event, Booking $booking): void
    {
        try {
            app(AdminOpsAlertService::class)->cancellationEvent($event, $booking);
        } catch (\Throwable $e) {
            Log::warning("Cancellation admin alert [{$event}] failed: ".$e->getMessage());
        }
    }

    private function result(string $outcome, Booking $booking, float $charge, ?BookingCancellationRequest $request = null, ?array $order = null, bool $already = false): array
    {
        return ['outcome' => $outcome, 'booking' => $booking, 'charge' => $charge, 'request' => $request, 'order' => $order, 'already' => $already];
    }
}
