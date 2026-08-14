<?php

namespace App\Http\Controllers\API;

use App\Contracts\PaymentGateway;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PaymentWebhookLog;
use App\Models\Setting;
use Illuminate\Http\Request;
use App\Services\Payments\RazorpayWebhookHandler;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    /**
     * POST /api/v1/bookings/{booking}/pay/create-order
     * Called by the customer app right before showing the gateway's
     * checkout screen. Creates a local `payments` row in `pending` status
     * and a matching gateway order.
     */
    public function createOrder(Request $request, int $bookingId, PaymentGateway $gateway)
    {
        $booking = Booking::findOrFail($bookingId);

        if ($booking->customer_id !== $request->user()->id) {
            return response()->json(['message' => 'Not your booking.'], 403);
        }

        $scope = array_filter(['zone_id' => $booking->zone_id, 'franchise_id' => $booking->franchise_id]);
        if (Setting::get('payment.online_enabled', '1', $scope) !== '1') {
            return response()->json(['message' => 'Online payments are currently disabled.'], 422);
        }

        $order = $gateway->createOrder($booking);

        $payment = Payment::create([
            'booking_id' => $booking->id,
            'amount' => $booking->price_quoted,
            'gateway' => $gateway->identifier(),
            'gateway_order_id' => $order['razorpay_order_id'],
            'status' => 'pending',
        ]);

        return response()->json([
            'payment_id' => $payment->id,
            'razorpay_order_id' => $order['razorpay_order_id'],
            'razorpay_key_id' => $order['key_id'],
            'amount' => $order['amount'],
            'currency' => $order['currency'],
        ]);
    }

    /**
     * POST /api/v1/bookings/{booking}/pay/confirm
     * Called by the customer app immediately after Razorpay's checkout SDK
     * returns a successful payment client-side. This is a fast-path UI
     * update ONLY — it verifies the checkout signature and marks the payment
     * captured for immediate app feedback, but the webhook below is the
     * actual source of truth (this call can be spoofed by a modified client;
     * the webhook, being server-to-server, cannot).
     */
    public function confirm(Request $request, int $bookingId, PaymentGateway $gateway)
    {
        $validated = $request->validate([
            'razorpay_order_id' => 'required|string',
            'razorpay_payment_id' => 'required|string',
            'razorpay_signature' => 'required|string',
        ]);

        $verified = $gateway->verifyPaymentSignature(
            $validated['razorpay_order_id'],
            $validated['razorpay_payment_id'],
            $validated['razorpay_signature'],
        );

        if (!$verified) {
            return response()->json(['message' => 'Payment signature verification failed.'], 422);
        }

        $payment = Payment::where('gateway_order_id', $validated['razorpay_order_id'])->firstOrFail();
        $payment->gateway_payment_id = $validated['razorpay_payment_id'];
        $payment->gateway_signature = $validated['razorpay_signature'];
        // Status intentionally NOT set to 'captured' here — see webhook() below.
        $payment->save();

        return response()->json(['message' => 'Payment recorded, awaiting confirmation.']);
    }

    /**
     * POST /api/v1/webhooks/razorpay
     * Server-to-server callback from Razorpay — this is the actual source
     * of truth for payment status, since (unlike the confirm() endpoint
     * above) it cannot be spoofed by a compromised or modified client app.
     *
     * Idempotent: Razorpay retries webhooks on any non-2xx response, so this
     * must be safe to receive the same event more than once.
     */
    public function webhook(Request $request, PaymentGateway $gateway, RazorpayWebhookHandler $handler)
    {
        $rawPayload = $request->getContent();
        $signature = $request->header('X-Razorpay-Signature', '');
        $signatureValid = $gateway->verifyWebhookSignature($rawPayload, $signature);
        $payload = json_decode($rawPayload, true) ?? [];
        $event = $payload['event'] ?? null;

        // Every receipt is logged regardless of outcome (Operations
        // expansion, mission Phase 10) -- an invalid signature or an
        // unmatched order_id used to be only a Log::warning() line, not
        // queryable/browsable anywhere and, worse, silently unrecoverable
        // since a 200 response tells Razorpay to stop retrying.
        if (! $signatureValid) {
            Log::warning('Razorpay webhook signature verification failed.');
            PaymentWebhookLog::create([
                'event' => $event, 'signature_valid' => false, 'processed' => false,
                'outcome' => 'invalid_signature', 'payload' => $payload,
            ]);

            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        $orderId = $payload['payload']['payment']['entity']['order_id'] ?? null;
        $paymentIdFromPayload = $payload['payload']['payment']['entity']['id'] ?? null;

        $result = ['outcome' => 'unhandled_event', 'payment' => null];
        if ($event === 'payment.captured') {
            $result = $handler->handleCaptured($payload);
        } elseif ($event === 'payment.failed') {
            $result = $handler->handleFailed($payload);
        }
        // Other event types (refund.processed, etc.) are logged but not
        // handled yet — safe to ignore until those flows are built.

        PaymentWebhookLog::create([
            'event' => $event, 'gateway_order_id' => $orderId, 'gateway_payment_id' => $paymentIdFromPayload,
            'payment_id' => $result['payment']?->id, 'signature_valid' => true,
            'processed' => in_array($result['outcome'], ['captured', 'failed', 'already_processed'], true),
            'outcome' => $result['outcome'], 'payload' => $payload,
        ]);

        // Always return 200 for any recognized, signature-valid webhook,
        // even for event types we don't act on — a non-2xx tells Razorpay
        // to keep retrying forever, which we don't want for events we're
        // intentionally not handling. 'unmatched_order' is the one real
        // gap this can't self-heal (nothing to retry against locally) --
        // it's now visible in the webhook log for admin reprocessing
        // instead of silently vanishing.
        return response()->json(['status' => 'ok']);
    }
}
