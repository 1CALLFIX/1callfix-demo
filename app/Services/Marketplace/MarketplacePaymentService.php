<?php

namespace App\Services\Marketplace;

use App\Contracts\PaymentGateway;
use App\Models\MarketplaceOrder;
use App\Models\Payment;
use App\Models\Setting;
use App\Notifications\MarketplaceOrderStatusNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\CancellationService;
use Illuminate\Support\Facades\DB;

/**
 * E-commerce Phase 0 (2026-10-25) — Razorpay ('online') payment for a marketplace order. Before this an online
 * order was placed and handed to the seller with no money taken. Same shape as ParcelPaymentService: a raw
 * gateway order + a pending `payments` row (purpose 'marketplace_order'), and only the capture WEBHOOK marks
 * the order paid. The seller cannot accept it before then (AdvanceMarketplaceOrderAction).
 */
class MarketplacePaymentService
{
    public function __construct(private PaymentGateway $gateway)
    {
    }

    /**
     * @return array{payment_id: int, razorpay_order_id: string, razorpay_key_id: ?string, amount: int, currency: string}
     */
    public function startOnlinePayment(MarketplaceOrder $order): array
    {
        if (! $order->isAwaitingOnlinePayment()) {
            throw new \RuntimeException('This order does not need an online payment.');
        }
        if (! $this->gateway->isConfigured()) {
            throw new \RuntimeException('Online payment is not available right now. Please try again shortly.');
        }
        if (Setting::get('payment.online_enabled', '1', array_filter(['zone_id' => $order->zone_id, 'franchise_id' => $order->franchise_id])) !== '1') {
            throw new \RuntimeException('Online payments are currently disabled.');
        }

        $gatewayOrder = $this->gateway->createRawOrder(
            (float) $order->total_amount,
            'mkt-'.$order->id.'-'.now()->format('His'),
            ['marketplace_order_id' => $order->id, 'purpose' => 'marketplace_order'],
        );

        $payment = Payment::create([
            'marketplace_order_id' => $order->id,
            'purpose' => 'marketplace_order',
            'amount' => $order->total_amount,
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
     * Webhook capture (after RazorpayWebhookHandler's amount check and pending→captured lock, so once per
     * payment). Marks the order paid and tells the customer — or refunds the money if the order was cancelled
     * meanwhile (unpaid expiry / admin) or was already paid by an earlier capture.
     */
    public function markPaid(Payment $payment): void
    {
        $order = $payment->marketplaceOrder;
        if (! $order) {
            return;
        }

        $outcome = DB::transaction(function () use ($order) {
            $locked = MarketplaceOrder::whereKey($order->id)->lockForUpdate()->first();
            if (! $locked) {
                return 'missing';
            }
            if ($locked->status === 'cancelled') {
                return 'cancelled';
            }
            if ($locked->payment_status === 'paid') {
                return 'already_paid';
            }

            $locked->payment_status = 'paid';
            $locked->save();

            $locked->statusHistory()->create([
                'status' => $locked->status,
                'note' => 'Payment received online',
                'changed_at' => now(),
            ]);

            return 'paid';
        });

        $order = $order->fresh();

        if ($outcome === 'paid') {
            if ($order->customer) {
                $channels = ChannelResolver::resolve(['zone_id' => $order->zone_id, 'franchise_id' => $order->franchise_id]);
                $order->customer->notify(new MarketplaceOrderStatusNotification('created', $order, $channels));
            }
            MarketplaceOrderStatusNotification::toSeller($order);

            return;
        }

        if (in_array($outcome, ['cancelled', 'already_paid'], true)) {
            app(CancellationService::class)->refundMarketplacePayment($order, $payment, $outcome === 'cancelled' ? (float) $order->cancellation_fee : 0.0);
        }
    }
}
