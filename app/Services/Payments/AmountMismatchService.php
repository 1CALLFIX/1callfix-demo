<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Models\Payment;
use App\Models\PaymentWebhookLog;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\PaymentUnderReviewNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Follow-up for a Razorpay capture whose amount differed from the Payment
 * row (RazorpayWebhookHandler::OUTCOME_AMOUNT_MISMATCH). Two jobs:
 *
 *  - tell the customer their payment is under review (copy is
 *    admin-editable, see COPY_KEY);
 *  - let a Super Admin refund EXACTLY what Razorpay captured, on demand.
 *    Never automatic: a human decides, gives a reason, and it is audited.
 *
 * The Payment row is deliberately left untouched by the refund — it was
 * never marked captured, so nothing downstream (booking paid, wallet
 * credit) ever fired and nothing needs unwinding. The refund state lives
 * on the webhook log row (outcome REFUNDED_OUTCOME) and in the activity log.
 */
class AmountMismatchService
{
    public const COPY_KEY = 'payments.mismatch_customer_message';

    public const DEFAULT_COPY = "Your payment is under review. We'll update you within 24 hours.";

    public const REFUNDED_OUTCOME = 'amount_mismatch_refunded';

    public function __construct(private PaymentGateway $gateway)
    {
    }

    public function customerMessage(): string
    {
        $copy = trim((string) Setting::get(self::COPY_KEY, ''));

        return $copy !== '' ? $copy : self::DEFAULT_COPY;
    }

    /** Best-effort, once per gateway payment: a redelivered or reprocessed event must not message the customer twice. */
    public function notifyCustomer(Payment $payment, ?string $gatewayPaymentId): void
    {
        try {
            $customer = $payment->user ?? $payment->booking?->customer ?? $payment->bookingBundle?->customer;

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

    /**
     * Refunds exactly the amount the webhook reported as captured.
     * Idempotent: the log row is locked and re-checked, and any sibling
     * log row for the same gateway payment that is already refunded blocks
     * a second refund. A gateway failure leaves everything as it was (retry
     * is possible) and is audit-logged.
     *
     * @return array{ok: bool, message: string}
     */
    public function refundCaptured(PaymentWebhookLog $log, User $admin, string $reason): array
    {
        if ($admin->role !== 'super_admin') {
            return ['ok' => false, 'message' => 'Only a Super Admin can refund a mismatched payment.'];
        }

        $reason = trim($reason);
        if ($reason === '') {
            return ['ok' => false, 'message' => 'A reason is required.'];
        }

        return DB::transaction(function () use ($log, $admin, $reason) {
            $row = PaymentWebhookLog::whereKey($log->id)->lockForUpdate()->firstOrFail();

            if ($row->outcome === self::REFUNDED_OUTCOME) {
                return ['ok' => false, 'message' => 'This payment has already been refunded.'];
            }

            if ($row->outcome !== RazorpayWebhookHandler::OUTCOME_AMOUNT_MISMATCH) {
                return ['ok' => false, 'message' => 'Only amount-mismatch entries can be refunded here.'];
            }

            $entity = $row->payload['payload']['payment']['entity'] ?? [];
            $gatewayPaymentId = $row->gateway_payment_id ?: ($entity['id'] ?? null);
            $capturedPaise = $entity['amount'] ?? null;

            if (! $gatewayPaymentId
                || (! is_int($capturedPaise) && ! (is_string($capturedPaise) && ctype_digit($capturedPaise)))
                || (int) $capturedPaise <= 0) {
                return ['ok' => false, 'message' => 'The captured amount or payment id is missing from this entry, so it cannot be refunded here.'];
            }

            $alreadyRefunded = PaymentWebhookLog::where('gateway_payment_id', $gatewayPaymentId)
                ->where('outcome', self::REFUNDED_OUTCOME)
                ->exists();
            if ($alreadyRefunded) {
                return ['ok' => false, 'message' => 'This gateway payment has already been refunded.'];
            }

            $amount = round(((int) $capturedPaise) / 100, 2);

            try {
                $refund = $this->gateway->refund($gatewayPaymentId, $amount, 'Amount mismatch refund: '.$reason);
            } catch (\Throwable $e) {
                ActivityLogger::logModel($admin, $row, "Mismatch refund FAILED for webhook log #{$row->id}", [
                    'gateway_payment_id' => $gatewayPaymentId,
                    'amount' => $amount,
                    'reason' => $reason,
                    'error' => $e->getMessage(),
                ]);

                return ['ok' => false, 'message' => 'The gateway refused the refund. Nothing changed; you can retry. Details are in the activity log.'];
            }

            $row->update(['outcome' => self::REFUNDED_OUTCOME, 'processed' => true]);

            ActivityLogger::logModel($admin, $row, "Refunded mismatched payment from webhook log #{$row->id}", [
                'gateway_payment_id' => $gatewayPaymentId,
                'amount' => $amount,
                'reason' => $reason,
                'gateway_refund_id' => is_array($refund) ? ($refund['id'] ?? null) : null,
                'payment_id' => $row->payment_id,
            ]);

            return ['ok' => true, 'message' => '₹'.number_format($amount, 2).' refunded to the customer\'s original payment method.'];
        });
    }
}
