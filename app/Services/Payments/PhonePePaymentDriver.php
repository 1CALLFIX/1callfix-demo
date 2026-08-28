<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Models\Booking;

/**
 * Payment Gateway Manager session — stub only, same posture as
 * PaytmPaymentDriver (see its own docblock): PhonePe merchant onboarding
 * hasn't happened, this exists so the admin screen can list "PhonePe" as a
 * driver to start configuring ahead of time, and it can never actually be
 * activated (PaymentGatewayManager::ACTIVATABLE_DRIVERS + the admin
 * screen's own guard both refuse it independently). Every action method
 * throws rather than silently mishandling a real payment.
 */
class PhonePePaymentDriver implements PaymentGateway
{
    public function __construct(private ?string $merchantId = null, private ?string $saltKey = null, private ?string $saltIndex = null)
    {
    }

    public function identifier(): string
    {
        return 'phonepe';
    }

    public function displayName(): string
    {
        return 'PhonePe';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function checkoutKeyId(): ?string
    {
        return null;
    }

    public function maskedPublicIdentifier(): ?string
    {
        return null;
    }

    public function createOrder(Booking $booking): array
    {
        throw new \RuntimeException('PhonePe integration is not yet available — merchant onboarding is still pending.');
    }

    public function createRawOrder(float $amountRupees, string $receipt, array $notes = []): array
    {
        throw new \RuntimeException('PhonePe integration is not yet available — merchant onboarding is still pending.');
    }

    public function verifyWebhookSignature(string $rawPayload, string $signatureHeader): bool
    {
        throw new \RuntimeException('PhonePe integration is not yet available — merchant onboarding is still pending.');
    }

    public function verifyPaymentSignature(string $orderId, string $paymentId, string $signature): bool
    {
        throw new \RuntimeException('PhonePe integration is not yet available — merchant onboarding is still pending.');
    }

    public function refund(string $gatewayPaymentId, float $amountRupees, string $reason = ''): array
    {
        throw new \RuntimeException('PhonePe integration is not yet available — merchant onboarding is still pending.');
    }
}
