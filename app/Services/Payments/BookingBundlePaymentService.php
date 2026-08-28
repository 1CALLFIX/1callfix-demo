<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Models\Booking;
use App\Models\BookingBundle;
use App\Models\Payment;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Phase E3 (Multi-Service Booking — Payment). Pays a BookingBundle through
 * the SAME payment architecture a single booking already uses:
 *
 *   - ONE Payment row (purpose = 'booking_bundle', booking_bundle_id set),
 *     never one per child.
 *   - ONE gateway order for the server-authoritative aggregate amount, via
 *     the existing PaymentGateway::createRawOrder() abstraction — the exact
 *     Booking-free order call WalletTopUpService already builds on. No second
 *     Razorpay client, no second signature verifier.
 *   - the existing /api/webhooks/razorpay endpoint + RazorpayWebhookHandler
 *     is what actually captures the payment and marks the bundle paid; this
 *     class only creates the pending order and records the (provisional)
 *     client-side confirmation, exactly like PaymentController does for a
 *     single booking.
 *
 * The aggregate amount is ALWAYS `total_price_final ?? total_price_quoted`
 * (the Phase-D / E2 authoritative total, frozen at bundle creation from the
 * summed server-computed child prices) — never anything from the request.
 */
class BookingBundlePaymentService
{
    /** Thrown when create-order is called for a bundle that is not awaiting payment (already paid / refunded). */
    public const NOT_PAYABLE_MESSAGE = 'This booking bundle is not awaiting payment.';

    /**
     * Create — or, if one is already open, re-return — the single pending
     * gateway order for a bundle's authoritative aggregate amount.
     *
     * Idempotent: the bundle row is locked for the duration, and a second
     * call while a pending 'booking_bundle' Payment already exists for this
     * bundle on the same gateway returns that same order instead of opening
     * a second one (mission E3 §7 / §22.2 — "exactly one payment record is
     * created/reused"). A brand-new order is only created after the previous
     * attempt has left 'pending' (captured or failed).
     *
     * @return array{payment_id:int, razorpay_order_id:string, razorpay_key_id:?string, amount:int, currency:string}
     *
     * @throws \RuntimeException when the bundle is not awaiting payment
     */
    public function createOrder(BookingBundle $bundle): array
    {
        $gateway = app(PaymentGateway::class);

        return DB::transaction(function () use ($bundle, $gateway) {
            /** @var BookingBundle $locked */
            $locked = BookingBundle::query()->whereKey($bundle->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->payment_status !== 'pending') {
                throw new \RuntimeException(self::NOT_PAYABLE_MESSAGE);
            }

            $amountRupees = $this->authoritativeAmount($locked);

            $existing = Payment::query()
                ->where('booking_bundle_id', $locked->id)
                ->where('purpose', 'booking_bundle')
                ->where('gateway', $gateway->identifier())
                ->where('status', 'pending')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return [
                    'payment_id' => $existing->id,
                    'razorpay_order_id' => $existing->gateway_order_id,
                    'razorpay_key_id' => $gateway->checkoutKeyId(),
                    // Recomputed from the stored authoritative amount the same
                    // way the driver does it — the client never influences this.
                    'amount' => (int) round((float) $existing->amount * 100),
                    'currency' => 'INR',
                ];
            }

            $order = $gateway->createRawOrder(
                $amountRupees,
                'bundle-'.$locked->code,
                ['booking_bundle_id' => $locked->id, 'franchise_id' => $locked->franchise_id, 'purpose' => 'booking_bundle'],
            );

            $payment = Payment::create([
                'booking_bundle_id' => $locked->id,
                'purpose' => 'booking_bundle',
                'amount' => $amountRupees,
                'gateway' => $gateway->identifier(),
                'gateway_order_id' => $order['razorpay_order_id'],
                'status' => 'pending',
            ]);

            return [
                'payment_id' => $payment->id,
                'razorpay_order_id' => $order['razorpay_order_id'],
                'razorpay_key_id' => $order['key_id'],
                'amount' => $order['amount'],
                'currency' => $order['currency'],
            ];
        });
    }

    /**
     * Record the client-side checkout result for a bundle payment.
     *
     * Provisional ONLY, exactly like PaymentController::confirm() for a single
     * booking: it stores the gateway payment id + signature for reference but
     * DELIBERATELY does not capture — the server-to-server webhook
     * (RazorpayWebhookHandler::handleCaptured) is the sole authority for the
     * pending -> captured transition and every downstream side effect. Safe
     * if the webhook has already arrived (the row is simply re-stamped with
     * the same values).
     *
     * The lookup is scoped to THIS bundle, so a valid order id belonging to a
     * different bundle / payment can never be used to touch this one.
     *
     * @param  array{razorpay_order_id:string, razorpay_payment_id:string, razorpay_signature:string}  $data
     *
     * @throws ModelNotFoundException when no pending 'booking_bundle' payment for this bundle matches the order id
     */
    public function recordConfirmation(BookingBundle $bundle, array $data): Payment
    {
        $payment = Payment::query()
            ->where('booking_bundle_id', $bundle->id)
            ->where('purpose', 'booking_bundle')
            ->where('gateway_order_id', $data['razorpay_order_id'])
            ->firstOrFail();

        $payment->forceFill([
            'gateway_payment_id' => $data['razorpay_payment_id'],
            'gateway_signature' => $data['razorpay_signature'],
            // status intentionally untouched — see the webhook path.
        ])->save();

        return $payment;
    }

    /**
     * The one place a successful bundle payment turns into "paid" state,
     * shared by the wallet path (CreateBookingBundleAction::payBundleWithWallet)
     * and the Razorpay webhook (RazorpayWebhookHandler::handleCaptured) so the
     * two can never diverge on what a paid bundle means for its children.
     *
     * Marks the bundle paid and propagates paid state to every child booking
     * (E2 deferred per-child settlement to "a later E-step" — this is it).
     * Idempotent: a child already 'paid' is left alone, and re-running the
     * whole method is a no-op.
     */
    public function markBundlePaid(BookingBundle $bundle): void
    {
        DB::transaction(function () use ($bundle) {
            if ($bundle->payment_status !== 'paid') {
                $bundle->forceFill(['payment_status' => 'paid'])->save();
            }

            Booking::query()
                ->where('booking_bundle_id', $bundle->id)
                ->where('payment_status', '!=', 'paid')
                ->update(['payment_status' => 'paid']);
        });
    }

    /**
     * Phase-D / E2 authoritative aggregate — the settled total if one exists,
     * else the frozen quote. Identical precedence to
     * BookingBundle::orderTotalPrice(). Never a request value.
     */
    private function authoritativeAmount(BookingBundle $bundle): float
    {
        return (float) ($bundle->total_price_final ?? $bundle->total_price_quoted);
    }
}
