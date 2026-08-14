<?php

namespace App\Contracts;

use App\Models\Booking;

/**
 * Swap the bound implementation (see AppServiceProvider::register(), same
 * pattern as SmsAdapter/PushAdapter) for a second real provider when one is
 * chosen — nothing above this interface (PaymentController, WalletTopUpService,
 * SubscriptionService, CancellationService) needs to change. Razorpay is the
 * one bound implementation today (RazorpayService) — this contract exists so
 * that stays true when a second provider is ever added, not because a second
 * one is being built now.
 *
 * Shaped directly from RazorpayService's own existing, already-working
 * public API — this did not invent new methods; it names the ones every
 * consumer already calls.
 */
interface PaymentGateway
{
    /** Short machine identifier stored on payments.gateway ('razorpay') — never the display name, never a secret. */
    public function identifier(): string;

    /** Human-readable label for the admin config screen. */
    public function displayName(): string;

    /**
     * True when this provider has real credentials configured (env vars
     * set) -- for the admin config screen's read-only status indicator, NOT
     * a secret itself. Does not distinguish test/live mode; Razorpay's own
     * key prefix (rzp_test_/rzp_live_) already communicates that safely
     * without exposing the secret half of the credential pair.
     */
    public function isConfigured(): bool;

    /**
     * A masked, safe-to-display fragment of the public key/account
     * identifier ONLY (e.g. "rzp_test_••••3821") -- never the secret. Null
     * when isConfigured() is false. For the admin config screen; never
     * returned by any API response.
     */
    public function maskedPublicIdentifier(): ?string;

    /**
     * Creates a gateway order for a booking's price_quoted amount, in the
     * gateway's own smallest currency unit internally.
     */
    public function createOrder(Booking $booking): array;

    /**
     * The general form createOrder() builds on — an order that isn't tied
     * to a Booking (wallet top-ups, plan/subscription purchases).
     */
    public function createRawOrder(float $amountRupees, string $receipt, array $notes = []): array;

    /** Verifies a server-to-server webhook payload actually came from the gateway, not a spoofed request. */
    public function verifyWebhookSignature(string $rawPayload, string $signatureHeader): bool;

    /** Verifies the signature the gateway's own checkout SDK returns to the client app after a successful payment. */
    public function verifyPaymentSignature(string $orderId, string $paymentId, string $signature): bool;

    /** Refunds part or all of a captured payment. */
    public function refund(string $gatewayPaymentId, float $amountRupees, string $reason = ''): array;
}
