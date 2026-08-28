<?php

namespace App\Contracts;

use App\Models\Booking;

/**
 * Swap the bound implementation (see PaymentGatewayManager::active(), the
 * single place App\Contracts\PaymentGateway now resolves through — see
 * AppServiceProvider::register()) for a second real provider when one is
 * chosen — nothing above this interface (PaymentController, WalletTopUpService,
 * SubscriptionService, CancellationService) needs to change. Razorpay
 * (RazorpayPaymentDriver) is the one real, fully-working implementation
 * today; PaytmPaymentDriver/PhonePePaymentDriver exist as stubs pending
 * merchant onboarding (see PaymentGatewayManager::ACTIVATABLE_DRIVERS).
 *
 * Shaped directly from RazorpayPaymentDriver's own existing,
 * already-working public API (formerly RazorpayService, moved/renamed in
 * the Payment Gateway Manager session) — this did not invent new methods;
 * it names the ones every consumer already calls. Mapped onto the
 * "initiate/verifyPayment/handleWebhook/refund" shape a payment gateway
 * contract conceptually needs: createOrder()/createRawOrder() ≈ initiate,
 * verifyPaymentSignature() (checkout-side) + verifyWebhookSignature()
 * (server-to-server) together ≈ verifyPayment()/handleWebhook(), refund()
 * ≈ refund — kept as two explicit signature-verification methods rather
 * than one generic pair because that's the real distinction Razorpay's own
 * API makes (see each method's own docblock), and collapsing them would
 * have meant renaming/restructuring every existing, already-money-tested
 * call site for a naming preference alone.
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
     * The UNMASKED public checkout key the client SDK needs to open the
     * gateway's checkout screen (Razorpay's `key_id`). Not a secret — it is
     * already returned inside every createOrder()/createRawOrder() response
     * ('key_id'); this exposes the same value on its own so a caller that is
     * re-returning an already-created pending order (idempotent
     * create-order) can rebuild the full client payload without opening a
     * second gateway order. Null when isConfigured() is false / for the
     * onboarding stubs.
     */
    public function checkoutKeyId(): ?string;

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
