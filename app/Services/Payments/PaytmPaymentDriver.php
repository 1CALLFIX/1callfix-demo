<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Models\Booking;

/**
 * Payment Gateway Manager session — stub only. Paytm merchant onboarding
 * (a business/paperwork step with Paytm, not a coding one) has not been
 * completed, so this class exists purely so the admin screen can list
 * "Paytm" as a driver an admin can start configuring credentials for ahead
 * of time — it can never actually be activated
 * (see PaymentGatewayManager::ACTIVATABLE_DRIVERS, and the admin screen's
 * own activation guard, which both refuse it independently). Every
 * action method below throws rather than silently mishandling a real
 * payment, same "fail loudly, never silently" posture as
 * RazorpayPaymentDriver's own construction-safety docblock.
 */
class PaytmPaymentDriver implements PaymentGateway
{
    public function __construct(private ?string $merchantId = null, private ?string $merchantKey = null, private ?string $website = null)
    {
    }

    public function identifier(): string
    {
        return 'paytm';
    }

    public function displayName(): string
    {
        return 'Paytm';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function maskedPublicIdentifier(): ?string
    {
        return null;
    }

    public function createOrder(Booking $booking): array
    {
        throw new \RuntimeException('Paytm integration is not yet available — merchant onboarding is still pending.');
    }

    public function createRawOrder(float $amountRupees, string $receipt, array $notes = []): array
    {
        throw new \RuntimeException('Paytm integration is not yet available — merchant onboarding is still pending.');
    }

    public function verifyWebhookSignature(string $rawPayload, string $signatureHeader): bool
    {
        throw new \RuntimeException('Paytm integration is not yet available — merchant onboarding is still pending.');
    }

    public function verifyPaymentSignature(string $orderId, string $paymentId, string $signature): bool
    {
        throw new \RuntimeException('Paytm integration is not yet available — merchant onboarding is still pending.');
    }

    public function refund(string $gatewayPaymentId, float $amountRupees, string $reason = ''): array
    {
        throw new \RuntimeException('Paytm integration is not yet available — merchant onboarding is still pending.');
    }
}
