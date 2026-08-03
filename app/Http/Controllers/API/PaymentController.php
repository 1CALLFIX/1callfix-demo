<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Http\Request;
use App\Services\RazorpayService;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    /**
     * POST /api/v1/bookings/{booking}/pay/create-order
     * Called by the customer app right before showing Razorpay's checkout
     * screen. Creates a local `payments` row in `pending` status and a
     * matching Razorpay order.
     */
    public function createOrder(Request $request, int $bookingId, RazorpayService $razorpay)
    {
        $booking = Booking::findOrFail($bookingId);

        if ($booking->customer_id !== $request->user()->id) {
            return response()->json(['message' => 'Not your booking.'], 403);
        }

        $order = $razorpay->createOrder($booking);

        $payment = Payment::create([
            'booking_id' => $booking->id,
            'amount' => $booking->price_quoted,
            'gateway' => 'razorpay',
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
    public function confirm(Request $request, int $bookingId, RazorpayService $razorpay)
    {
        $validated = $request->validate([
            'razorpay_order_id' => 'required|string',
            'razorpay_payment_id' => 'required|string',
            'razorpay_signature' => 'required|string',
        ]);

        $verified = $razorpay->verifyPaymentSignature(
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
    public function webhook(Request $request, RazorpayService $razorpay)
    {
        $rawPayload = $request->getContent();
        $signature = $request->header('X-Razorpay-Signature', '');

        if (!$razorpay->verifyWebhookSignature($rawPayload, $signature)) {
            Log::warning('Razorpay webhook signature verification failed.');
            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        $payload = json_decode($rawPayload, true);
        $event = $payload['event'] ?? null;

        if ($event === 'payment.captured') {
            $this->handlePaymentCaptured($payload);
        } elseif ($event === 'payment.failed') {
            $this->handlePaymentFailed($payload);
        }
        // Other event types (refund.processed, etc.) are logged but not
        // handled yet — safe to ignore until those flows are built.

        // Always return 200 for any recognized, signature-valid webhook,
        // even for event types we don't act on — a non-2xx tells Razorpay
        // to keep retrying forever, which we don't want for events we're
        // intentionally not handling.
        return response()->json(['status' => 'ok']);
    }

    private function handlePaymentCaptured(array $payload): void
    {
        $razorpayOrderId = $payload['payload']['payment']['entity']['order_id'] ?? null;
        $razorpayPaymentId = $payload['payload']['payment']['entity']['id'] ?? null;

        if (!$razorpayOrderId) {
            return;
        }

        $payment = Payment::where('gateway_order_id', $razorpayOrderId)->first();
        if (!$payment) {
            Log::warning("Razorpay webhook: no local payment found for order [{$razorpayOrderId}].");
            return;
        }

        // Idempotency guard — if we've already processed this as captured,
        // do nothing further (prevents double-marking on webhook retries).
        if ($payment->status === 'captured') {
            return;
        }

        $payment->status = 'captured';
        $payment->gateway_payment_id = $razorpayPaymentId;
        $payment->captured_at = now();
        $payment->save();

        $booking = $payment->booking;
        $booking->payment_status = 'paid';
        $booking->save();
    }

    private function handlePaymentFailed(array $payload): void
    {
        $razorpayOrderId = $payload['payload']['payment']['entity']['order_id'] ?? null;
        if (!$razorpayOrderId) {
            return;
        }

        $payment = Payment::where('gateway_order_id', $razorpayOrderId)->first();
        if ($payment && $payment->status !== 'captured') {
            $payment->status = 'failed';
            $payment->save();
        }
    }
}
