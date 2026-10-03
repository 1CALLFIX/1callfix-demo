<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Models\Booking;
use App\Services\AdminOpsAlertService;
use Illuminate\Support\Facades\Log;

/**
 * Wraps the active PaymentGateway so that a failed gateway refund raises the
 * `refund_failed` admin alert (email, see AdminOpsAlertService) no matter
 * which code path asked for the refund — customer/admin/provider
 * cancellations, bundle settlement, charge waivers, mismatch refunds, and
 * anything added later. Several of those paths catch the exception and only
 * log it; this makes sure a human is told. Pure pass-through otherwise, and
 * the exception is always re-thrown unchanged so every caller behaves
 * exactly as before.
 */
class RefundAlertingGateway implements PaymentGateway
{
    public function __construct(private PaymentGateway $inner)
    {
    }

    /** The real driver underneath (tests and diagnostics; production code only ever talks to the PaymentGateway contract). */
    public function unwrap(): PaymentGateway
    {
        return $this->inner;
    }

    public function identifier(): string
    {
        return $this->inner->identifier();
    }

    public function displayName(): string
    {
        return $this->inner->displayName();
    }

    public function isConfigured(): bool
    {
        return $this->inner->isConfigured();
    }

    public function maskedPublicIdentifier(): ?string
    {
        return $this->inner->maskedPublicIdentifier();
    }

    public function checkoutKeyId(): ?string
    {
        return $this->inner->checkoutKeyId();
    }

    public function createOrder(Booking $booking): array
    {
        return $this->inner->createOrder($booking);
    }

    public function createRawOrder(float $amountRupees, string $receipt, array $notes = []): array
    {
        return $this->inner->createRawOrder($amountRupees, $receipt, $notes);
    }

    public function verifyWebhookSignature(string $rawPayload, string $signatureHeader): bool
    {
        return $this->inner->verifyWebhookSignature($rawPayload, $signatureHeader);
    }

    public function verifyPaymentSignature(string $orderId, string $paymentId, string $signature): bool
    {
        return $this->inner->verifyPaymentSignature($orderId, $paymentId, $signature);
    }

    public function refund(string $gatewayPaymentId, float $amountRupees, string $reason = ''): array
    {
        try {
            return $this->inner->refund($gatewayPaymentId, $amountRupees, $reason);
        } catch (\Throwable $e) {
            try {
                app(AdminOpsAlertService::class)->refundFailed($gatewayPaymentId);
            } catch (\Throwable $alertError) {
                Log::warning('RefundAlertingGateway: could not raise the refund-failed alert.', ['error' => $alertError->getMessage()]);
            }

            throw $e;
        }
    }
}
