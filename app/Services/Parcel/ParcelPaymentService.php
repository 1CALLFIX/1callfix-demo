<?php

namespace App\Services\Parcel;

use App\Actions\CreateParcelOrderAction;
use App\Contracts\PaymentGateway;
use App\Models\ParcelOrder;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\CancellationService;
use Illuminate\Support\Facades\DB;

/**
 * Razorpay ('online') payment for a parcel order — the gap KNOWN_RISKS item 31 left open. Same shape as a
 * wallet top-up: a raw gateway order + a pending `payments` row (purpose 'parcel_order'), and the capture
 * WEBHOOK (never the browser) is what marks the order paid. Only then is it dispatched to riders, so no rider
 * ever travels for an unpaid online order.
 */
class ParcelPaymentService
{
    public function __construct(private PaymentGateway $gateway)
    {
    }

    /**
     * Opens a gateway order for the customer to pay. Returns the checkout payload the page hands to Razorpay.
     *
     * @return array{payment_id: int, razorpay_order_id: string, razorpay_key_id: ?string, amount: int, currency: string}
     */
    public function startOnlinePayment(ParcelOrder $order): array
    {
        if (! $order->isAwaitingOnlinePayment()) {
            throw new \RuntimeException('This parcel order does not need an online payment.');
        }
        if (! $this->gateway->isConfigured()) {
            throw new \RuntimeException('Online payment is not available right now. Please choose another payment method.');
        }
        if (Setting::get('payment.online_enabled', '1', array_filter(['zone_id' => $order->zone_id, 'franchise_id' => $order->franchise_id])) !== '1') {
            throw new \RuntimeException('Online payments are currently disabled.');
        }

        $gatewayOrder = $this->gateway->createRawOrder(
            (float) $order->price_quoted,
            'parcel-'.$order->id.'-'.now()->format('His'),
            ['parcel_order_id' => $order->id, 'purpose' => 'parcel_order'],
        );

        $payment = Payment::create([
            'parcel_order_id' => $order->id,
            'purpose' => 'parcel_order',
            'amount' => $order->price_quoted,
            'gateway' => $this->gateway->identifier(),
            'gateway_order_id' => $gatewayOrder['razorpay_order_id'],
            'status' => 'pending',
        ]);

        return [
            'payment_id' => $payment->id,
            'razorpay_order_id' => $gatewayOrder['razorpay_order_id'],
            'razorpay_key_id' => $gatewayOrder['key_id'],
            'amount' => $gatewayOrder['amount'],
            'currency' => $gatewayOrder['currency'],
        ];
    }

    /**
     * Webhook capture (RazorpayWebhookHandler, after its amount check and pending→captured lock, so this runs
     * once per payment). Marks the order paid and dispatches it — unless it was cancelled meanwhile (customer
     * cancel or unpaid expiry), in which case the money goes straight back.
     */
    public function markPaid(Payment $payment): void
    {
        $order = $payment->parcelOrder;
        if (! $order) {
            return;
        }

        $outcome = DB::transaction(function () use ($order) {
            $locked = ParcelOrder::whereKey($order->id)->lockForUpdate()->first();
            if (! $locked) {
                return 'missing';
            }
            if ($locked->status === 'cancelled') {
                return 'cancelled';
            }
            if ($locked->payment_status === 'paid') {
                return 'already_paid'; // a second capture for the same order: refunded below, never dispatched twice
            }

            $locked->payment_status = 'paid';
            $locked->save();

            return 'paid';
        });

        if ($outcome === 'paid') {
            CreateParcelOrderAction::dispatchAndNotify($order->fresh());

            return;
        }

        if (in_array($outcome, ['cancelled', 'already_paid'], true)) {
            app(CancellationService::class)->refundParcelPayment($order->fresh(), $payment, $outcome === 'cancelled' ? (float) $order->cancellation_fee : 0.0);
        }
    }
}
