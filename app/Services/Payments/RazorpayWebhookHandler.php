<?php

namespace App\Services\Payments;

use App\Models\Booking;
use App\Models\Payment;
use App\Notifications\PaymentStatusNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\AdminOpsAlertService;
use App\Services\CancellationService;
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
            //
            // REF 1CF-AUDIT-20260922-C01 — 'refunded' is also a terminal,
            // already-processed outcome for THIS event now (a payment that
            // got captured, then immediately auto-refunded because its
            // booking was already cancelled — see the booking-handling
            // block below). Without including it here, a retried webhook
            // delivery for the same event would find status='refunded'
            // (not 'captured'), fail this guard, and incorrectly flip the
            // payment back to 'captured' — undoing the refund bookkeeping
            // and risking a second refund attempt on the next cancellation
            // check.
            if (in_array($payment->status, ['captured', 'refunded'], true)) {
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

        // Phase 2 — real-time operational push to opted-in admins. Placed
        // here (once, after the pending->captured lock) so it fires exactly
        // once for EVERY newly-captured payment: booking, booking_bundle,
        // wallet_topup and plan_subscription alike.
        app(AdminOpsAlertService::class)->paymentCaptured($payment);

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

        // Phase E3 — multi-service bundle. ONE payment paid the aggregate, so
        // ONE capture marks the bundle paid and propagates that to every child
        // booking, then gives each child the same customer-facing
        // payment-received notification a standalone booking gets below. The
        // pending -> captured lock above guarantees this runs exactly once, so
        // a retried/duplicate webhook cannot double-notify or re-run it.
        if ($payment->purpose === 'booking_bundle') {
            $bundle = $payment->bookingBundle;
            if ($bundle) {
                app(BookingBundlePaymentService::class)->markBundlePaid($bundle);

                foreach ($bundle->children()->with('customer')->get() as $child) {
                    if ($child->customer) {
                        $channels = ChannelResolver::resolve(['zone_id' => $child->zone_id, 'franchise_id' => $child->franchise_id]);
                        $child->customer->notify(new PaymentStatusNotification('completed', $child, $channels));
                    }
                }
            }

            return ['outcome' => 'captured', 'payment' => $payment];
        }

        $booking = $payment->booking;
        if ($booking) {
            // REF 1CF-AUDIT-20260922-C01 — the capture transaction above only
            // locks the `payments` row. Nothing previously locked or
            // re-checked THIS booking's status before writing payment_status
            // onto it, so a booking cancelled concurrently with a
            // late-arriving capture could silently end up 'paid' with no
            // refund ever triggered (the booking's own cancellation already
            // ran its refundIfPaid() call before this payment existed to
            // refund). Lock + re-check the booking's CURRENT status first,
            // the same convention every other booking-mutating code path in
            // this app already follows (AcceptBookingAction,
            // AdminCancelBookingAction, ServiceMatchingJob, ...).
            $bookingWasAlreadyCancelled = false;

            DB::transaction(function () use ($booking, &$bookingWasAlreadyCancelled) {
                $locked = Booking::whereKey($booking->id)->lockForUpdate()->first();

                if (! $locked) {
                    return;
                }

                if ($locked->status === 'cancelled') {
                    $bookingWasAlreadyCancelled = true;

                    return;
                }

                $locked->payment_status = 'paid';
                $locked->save();
            });

            if ($bookingWasAlreadyCancelled) {
                // Refund AFTER the lock transaction commits, never inside
                // it — same convention AdminCancelBookingAction already
                // uses for its own refundIfPaid() call: an external gateway
                // HTTP call must not run while holding the booking row
                // lock. Reuses the booking's own already-decided
                // cancellation_fee (set when it was cancelled) rather than
                // inventing a new fee rule here — this fix closes the race,
                // it does not change cancellation-fee policy.
                // refundIfPaid() is itself idempotent (it only acts on a
                // Payment still in 'captured' status), so this is safe even
                // if reached more than once.
                app(CancellationService::class)->refundIfPaid($booking->fresh(), (float) $booking->cancellation_fee);
            } elseif ($booking->customer) {
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

        // Phase E3 — a failed bundle payment leaves the bundle AND every child
        // exactly as they were (payment_status = 'pending'); the payment row is
        // already marked 'failed' above. Only the per-child "payment failed"
        // notification is sent, mirroring the single-booking branch below.
        if ($payment->purpose === 'booking_bundle') {
            $bundle = $payment->bookingBundle;
            if ($bundle) {
                foreach ($bundle->children()->with('customer')->get() as $child) {
                    if ($child->customer) {
                        $channels = ChannelResolver::resolve(['zone_id' => $child->zone_id, 'franchise_id' => $child->franchise_id]);
                        $child->customer->notify(new PaymentStatusNotification('failed', $child, $channels));
                    }
                }
            }

            return ['outcome' => 'failed', 'payment' => $payment];
        }

        $booking = $payment->booking;
        if ($booking && $booking->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $booking->zone_id, 'franchise_id' => $booking->franchise_id]);
            $booking->customer->notify(new PaymentStatusNotification('failed', $booking, $channels));
        }

        return ['outcome' => 'failed', 'payment' => $payment];
    }
}
