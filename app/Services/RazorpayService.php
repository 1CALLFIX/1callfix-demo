<?php

namespace App\Services;

use App\Models\Booking;
use Illuminate\Support\Facades\Http;

class RazorpayService
{
    // Nullable, not string: config('services.razorpay.*') is null on any
    // environment without Razorpay credentials set (.env.example has no
    // RAZORPAY_* entries at all) — a plain `string` property crashed
    // construction itself with an uncaught TypeError the instant ANYTHING
    // that depends on this service (e.g. CancellationService, injected
    // into AdminCancelBookingAction) was resolved from the container, even
    // for a cancellation that never actually calls Razorpay (found via
    // testing this session: cancelling an unpaid booking crashed hard).
    // The API-calling methods below still fail loudly and clearly if
    // actually invoked without real credentials — Razorpay's API rejects
    // an unauthenticated request, which already surfaces as a RuntimeException
    // via the existing !$response->successful() check — just no longer at
    // construction time for code paths that never reach Razorpay at all.
    private ?string $keyId;
    private ?string $keySecret;
    private ?string $webhookSecret;

    public function __construct()
    {
        $this->keyId = config('services.razorpay.key_id');
        $this->keySecret = config('services.razorpay.key_secret');
        $this->webhookSecret = config('services.razorpay.webhook_secret');
    }

    /**
     * Creates a Razorpay order for a booking's price_quoted amount.
     * Called right before showing the checkout screen — the returned
     * order_id + key_id are what the Flutter app hands to Razorpay's
     * checkout SDK.
     *
     * Amount is sent in paise (Razorpay's smallest unit), matching how
     * they require it — price_quoted is stored in rupees in our DB.
     */
    public function createOrder(Booking $booking): array
    {
        return $this->createRawOrder(
            (float) $booking->price_quoted,
            $booking->code,
            ['booking_id' => $booking->id, 'franchise_id' => $booking->franchise_id],
        );
    }

    /**
     * The general form createOrder() (booking payments) and
     * WalletTopUpService (wallet top-ups) both build on — a Razorpay order
     * doesn't require a Booking, just an amount, a receipt reference and
     * some notes. Extracted here rather than duplicated so both callers
     * share one place that talks to Razorpay's orders API.
     */
    public function createRawOrder(float $amountRupees, string $receipt, array $notes = []): array
    {
        $response = Http::withBasicAuth($this->keyId, $this->keySecret)
            ->post('https://api.razorpay.com/v1/orders', [
                'amount' => (int) round($amountRupees * 100),
                'currency' => 'INR',
                'receipt' => $receipt,
                'notes' => $notes,
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException(
                "Razorpay order creation failed for receipt [{$receipt}]: " . $response->body()
            );
        }

        $order = $response->json();

        return [
            'razorpay_order_id' => $order['id'],
            'amount' => $order['amount'],
            'currency' => $order['currency'],
            'key_id' => $this->keyId, // safe to expose to the app — it's the public key
        ];
    }

    /**
     * Verifies a webhook payload actually came from Razorpay, not a spoofed
     * request. Razorpay signs the raw request body with the webhook secret
     * using HMAC-SHA256 — this must be checked before trusting ANY webhook
     * data, since payment status is money-critical.
     */
    public function verifyWebhookSignature(string $rawPayload, string $signatureHeader): bool
    {
        $expectedSignature = hash_hmac('sha256', $rawPayload, $this->webhookSecret);

        return hash_equals($expectedSignature, $signatureHeader);
    }

    /**
     * Verifies the signature Razorpay's checkout SDK returns to the app
     * after a successful payment (checkout-side, distinct from the webhook
     * signature above — Razorpay uses order_id|payment_id as the payload here).
     */
    public function verifyPaymentSignature(string $orderId, string $paymentId, string $signature): bool
    {
        $expectedSignature = hash_hmac('sha256', "{$orderId}|{$paymentId}", $this->keySecret);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Refunds part or all of a captured payment — used by CancellationService
     * when a cancellation fee leaves a remaining refundable amount. Amount
     * is sent in paise, same conversion createOrder() uses.
     */
    public function refund(string $razorpayPaymentId, float $amountRupees, string $reason = ''): array
    {
        $response = Http::withBasicAuth($this->keyId, $this->keySecret)
            ->post("https://api.razorpay.com/v1/payments/{$razorpayPaymentId}/refund", [
                'amount' => (int) round($amountRupees * 100),
                'notes' => ['reason' => $reason],
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException(
                "Razorpay refund failed for payment [{$razorpayPaymentId}]: " . $response->body()
            );
        }

        return $response->json();
    }
}
