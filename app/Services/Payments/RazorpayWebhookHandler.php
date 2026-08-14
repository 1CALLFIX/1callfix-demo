<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Notifications\PaymentStatusNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\Plans\SubscriptionService;
use App\Services\WalletTopUpService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Extracted verbatim from PaymentController's own former private
 * handlePaymentCaptured()/handlePaymentFailed() methods (Operations
 * expansion, mission Phase 10) so the SAME idempotent handler serves both
 * the live webhook endpoint and an admin-triggered "reprocess" action on a
 * previously unmatched/unhandled webhook log row -- not a second engine.
 * Every idempotency guarantee below is unchanged from the original.
 */
class RazorpayWebhookHandler
{
    /** @return array{outcome: string, payment: ?Payment} */
    public function handleCaptured(array $payload): array
    {
        $razorpayOrderId = $payload['payload']['payment']['entity']['order_id'] ?? null;
        $razorpayPaymentId = $payload['payload']['payment']['entity']['id'] ?? null;

        if (! $razorpayOrderId) {
            return ['outcome' => 'unhandled_event', 'payment' => null];
        }

        // Gateways (Razorpay included) retry webhook delivery on any non-2xx
        // response, and can genuinely deliver the same event concurrently
        // (two in-flight requests, not just sequential retries) -- the plain
        // "read status, then write" idempotency guard would have a real
        // TOCTOU window where both requests could read status !== 'captured'
        // before either writes. lockForUpdate() inside a transaction closes
        // it, the same row-locking convention every booking-mutating Action
        // in this codebase already uses.
        $alreadyCaptured = DB::transaction(function () use ($razorpayOrderId, $razorpayPaymentId, &$payment) {
            $payment = Payment::where('gateway_order_id', $razorpayOrderId)->lockForUpdate()->first();

            if (! $payment) {
                return null;
            }

            // Idempotency guard — if we've already processed this as
            // captured, do nothing further (prevents double-marking on
            // webhook retries/reprocessing, and double-crediting a wallet
            // top-up).
            if ($payment->status === 'captured') {
                return true;
            }

            $payment->status = 'captured';
            $payment->gateway_payment_id = $razorpayPaymentId;
            $payment->captured_at = now();
            $payment->save();

            return false;
        });

        if (! $payment) {
            Log::warning("Razorpay webhook: no local payment found for order [{$razorpayOrderId}].");

            return ['outcome' => 'unmatched_order', 'payment' => null];
        }

        if ($alreadyCaptured) {
            return ['outcome' => 'already_processed', 'payment' => $payment];
        }

        // Wallet top-up: credit the wallet, no booking involved.
        if ($payment->purpose === 'wallet_topup') {
            app(WalletTopUpService::class)->creditWalletForCapturedTopUp($payment);

            return ['outcome' => 'captured', 'payment' => $payment];
        }

        // Plan subscription purchase/renewal: activate the subscription, no
        // booking involved — same idempotency guard pattern as the top-up
        // branch above (SubscriptionService::activateAfterPayment() is a
        // no-op if the subscription isn't still pending_payment).
        if ($payment->purpose === 'plan_subscription') {
            app(SubscriptionService::class)->activateAfterPayment($payment);

            return ['outcome' => 'captured', 'payment' => $payment];
        }

        $booking = $payment->booking;
        if ($booking) {
            $booking->payment_status = 'paid';
            $booking->save();

            if ($booking->customer) {
                $channels = ChannelResolver::resolve(['zone_id' => $booking->zone_id, 'franchise_id' => $booking->franchise_id]);
                $booking->customer->notify(new PaymentStatusNotification('completed', $booking, $channels));
            }
        }

        return ['outcome' => 'captured', 'payment' => $payment];
    }

    /** @return array{outcome: string, payment: ?Payment} */
    public function handleFailed(array $payload): array
    {
        $razorpayOrderId = $payload['payload']['payment']['entity']['order_id'] ?? null;
        if (! $razorpayOrderId) {
            return ['outcome' => 'unhandled_event', 'payment' => null];
        }

        $payment = Payment::where('gateway_order_id', $razorpayOrderId)->first();
        if (! $payment) {
            return ['outcome' => 'unmatched_order', 'payment' => null];
        }

        if ($payment->status === 'captured') {
            return ['outcome' => 'already_processed', 'payment' => $payment];
        }

        $payment->status = 'failed';
        $payment->save();

        if ($payment->purpose === 'wallet_topup') {
            return ['outcome' => 'failed', 'payment' => $payment]; // nothing was ever credited — no booking, nothing to notify
        }

        if ($payment->purpose === 'plan_subscription') {
            app(SubscriptionService::class)->failPayment($payment);

            return ['outcome' => 'failed', 'payment' => $payment]; // subscription stays unactivated/unusable — no booking involved
        }

        $booking = $payment->booking;
        if ($booking && $booking->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $booking->zone_id, 'franchise_id' => $booking->franchise_id]);
            $booking->customer->notify(new PaymentStatusNotification('failed', $booking, $channels));
        }

        return ['outcome' => 'failed', 'payment' => $payment];
    }
}
