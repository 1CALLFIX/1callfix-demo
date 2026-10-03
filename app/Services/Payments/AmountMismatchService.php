<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\PaymentUnderReviewNotification;
use App\Notifications\RefundProcessedNotification;
use App\Notifications\Support\ChannelResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Customer-facing notices for a Razorpay capture whose amount did not match
 * (RazorpayWebhookHandler::OUTCOME_AMOUNT_MISMATCH): "under review" when it
 * is detected, "refund processed" when a refund succeeds. Both texts are
 * Super Admin-editable (Refund Controls screen). The refund itself, its
 * approval and its queue live in MismatchRefundService.
 */
class AmountMismatchService
{
    public const COPY_UNDER_REVIEW_KEY = 'refund.mismatch.notice_under_review';

    public const COPY_REFUNDED_KEY = 'refund.mismatch.notice_refunded';

    public const DEFAULT_UNDER_REVIEW = "Your payment is under review. We'll update you within 24 hours.";

    public const DEFAULT_REFUNDED = 'Your refund of ₹[amount] has been processed. It will reach your account in 5–7 working days.';

    public function customerMessage(): string
    {
        $copy = trim((string) Setting::get(self::COPY_UNDER_REVIEW_KEY, ''));

        return $copy !== '' ? $copy : self::DEFAULT_UNDER_REVIEW;
    }

    public function refundedMessage(float $amountRupees): string
    {
        $copy = trim((string) Setting::get(self::COPY_REFUNDED_KEY, ''));
        $copy = $copy !== '' ? $copy : self::DEFAULT_REFUNDED;

        return str_replace('[amount]', number_format($amountRupees, 2), $copy);
    }

    public function customerFor(Payment $payment): ?User
    {
        return $payment->user ?? $payment->booking?->customer ?? $payment->bookingBundle?->customer;
    }

    /** Best-effort, once per gateway payment: a redelivered or reprocessed event must not message the customer twice. */
    public function notifyCustomer(Payment $payment, ?string $gatewayPaymentId): void
    {
        try {
            $customer = $this->customerFor($payment);

            if (! $customer) {
                return;
            }

            $dedupeKey = 'payment-mismatch-notified:'.($gatewayPaymentId ?: 'payment-'.$payment->id);
            if (! Cache::add($dedupeKey, true, now()->addDays(7))) {
                return;
            }

            $customer->notify(new PaymentUnderReviewNotification($this->customerMessage(), ChannelResolver::resolve([])));
        } catch (\Throwable $e) {
            Log::warning('AmountMismatchService: could not notify the customer.', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);
        }
    }

    /** Best-effort. The caller guarantees once-only by claiming refund_notice_sent_at under the row lock first. */
    public function notifyRefunded(?Payment $payment, float $amountRupees): void
    {
        try {
            $customer = $payment ? $this->customerFor($payment) : null;

            if (! $customer) {
                return;
            }

            $customer->notify(new RefundProcessedNotification($this->refundedMessage($amountRupees), ChannelResolver::resolve([])));
        } catch (\Throwable $e) {
            Log::warning('AmountMismatchService: could not send the refund notice.', ['payment_id' => $payment?->id, 'error' => $e->getMessage()]);
        }
    }
}
