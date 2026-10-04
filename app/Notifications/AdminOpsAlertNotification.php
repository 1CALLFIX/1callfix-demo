<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Real-time operational visibility for admins who opt in
 * (users.push_ops_alerts) on the dashboard toggle. Fired by
 * App\Services\AdminOpsAlertService on two events Mohammed asked to see:
 *
 *   booking_created   — any booking, any status, any creation path
 *   payment_captured  — any Razorpay capture (booking, bundle, wallet
 *                       top-up, plan subscription)
 *
 * Push-only in practice: toMail()/toSms() are intentionally absent so that
 * even though ChannelResolver may return ['mail', PushChannel] this
 * notification only materialises on the push channel. Not a Notification
 * Center broadcast — see the migration docblock for the scoping rationale.
 */
class AdminOpsAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private string $event, private Model $subject, private array $channels)
    {
    }

    public function via($notifiable): array
    {
        return $this->channels;
    }

    public function eventKey(): string
    {
        return "admin.ops_{$this->event}";
    }

    public function pushLink($notifiable): string
    {
        if ($this->subject instanceof \App\Models\MismatchRefund) {
            return route('admin.mismatch-refunds.index');
        }

        if ($this->subject instanceof \App\Models\BookingDispute) {
            return route('admin.booking-disputes.index');
        }

        if ($this->subject instanceof Booking) {
            return route('admin.bookings.show', $this->subject->id);
        }

        if ($this->subject instanceof Payment && $this->subject->booking_id) {
            return route('admin.bookings.show', $this->subject->booking_id);
        }

        return route('admin.payments.index');
    }

    /** 0d — the email copy of the critical alerts (AdminOpsAlertService::EMAIL_TYPES); same wording as the push. */
    public function toMail($notifiable): MailMessage
    {
        $copy = $this->toPush($notifiable);

        return (new MailMessage)
            ->subject('[1CallFix Admin] '.$copy['title'])
            ->line($copy['body'])
            ->action('Open in the admin panel', $this->pushLink($notifiable));
    }

    public function toPush($notifiable): array
    {
        return match ($this->event) {
            'booking_created' => $this->bookingCreatedCopy(),
            'payment_captured' => $this->paymentCapturedCopy(),
            'payment_amount_mismatch' => ['title' => 'Payment amount mismatch', 'body' => "Razorpay reported a captured amount that differs from payment #{$this->subject->id}. It was NOT marked paid. Check the webhook log."],
            'dispatch_escalation' => $this->dispatchEscalationCopy(),
            'dispatch_job_failure' => $this->dispatchJobFailureCopy(),
            'dispatch_refund_failed' => $this->dispatchRefundFailedCopy(),
            'scheduled_early_warning' => $this->scheduledEarlyWarningCopy(),
            'scheduled_urgent_alert' => $this->scheduledUrgentAlertCopy(),
            'job_at_risk' => $this->jobAtRiskCopy(),
            'mismatch_refund_escalation' => $this->mismatchRefundEscalationCopy(),
            'dispute_refund_escalation' => $this->disputeRefundEscalationCopy(),
            'refund_failed' => ['title' => 'Refund failed', 'body' => "A gateway refund for payment {$this->subject->gateway_payment_id} failed. Money may be stuck: check Payments and the Razorpay dashboard."],
            // REF 1CF-CANCEL-POLICY-001
            'cancel_charge_unpaid' => ['title' => 'Cancellation charge unpaid', 'body' => "Booking {$this->subject->code} has a cancellation charge unpaid for over 7 days. Review it: collect, or waive with a reason."],
            'interim_dispute' => ['title' => 'Progress figures disputed', 'body' => "The customer disputed the declared progress on booking {$this->subject->code}. The cancellation is waiting for your review."],
            'cancel_payout_failed' => ['title' => 'Provider payout failed', 'body' => "The interim-work payout for cancelled booking {$this->subject->code} failed. It will be retried; check if it persists."],
            default => ['title' => 'Operations update', 'body' => 'An operational event occurred.'],
        };
    }

    /**
     * REF 1CF-IMPLEMENT-20260922-L01 — T+5, no provider assigned yet. Kept
     * as its own event key (not reusing 'booking_created') so an admin can
     * tell "still searching, needs a look" apart from "just came in".
     */
    private function dispatchEscalationCopy(): array
    {
        /** @var Booking $b */
        $b = $this->subject;
        $service = $b->service?->name ?? 'Service';
        $zone = $b->zone?->name ? " · {$b->zone->name}" : '';

        return [
            'title' => 'Dispatch escalation',
            'body' => "Booking {$b->code} — {$service}{$zone} — still has no provider 5 minutes in. Needs a look.",
        ];
    }

    /**
     * REF 1CF-IMPLEMENT-20260922-L01 — L-02 minimum. Distinct copy so a
     * queue/job failure never reads to an admin as an ordinary "nobody
     * accepted yet" escalation — see ServiceMatchingJob::failed().
     */
    private function dispatchJobFailureCopy(): array
    {
        /** @var Booking $b */
        $b = $this->subject;

        return [
            'title' => 'Dispatch job failed',
            'body' => "Booking {$b->code} — automated dispatch stopped due to a queue/job error, not a lack of providers. Needs manual attention.",
        ];
    }

    /**
     * REF 1CF-IMPLEMENT-20260922-L01 — a T+30 auto-cancellation whose
     * refund failed (DispatchDeadlineSweepService::cancelOne()'s catch).
     * The cancellation itself already went through; this is a distinct
     * "money needs a human" alert, not a dispatch-health one — no exception
     * text in the copy (payment error detail belongs in the log, not a
     * push notification), just where to look.
     */
    private function dispatchRefundFailedCopy(): array
    {
        /** @var Booking $b */
        $b = $this->subject;

        return [
            'title' => 'Refund failed on auto-cancel',
            'body' => "Booking {$b->code} was auto-cancelled, but its refund failed. Needs manual reconciliation.",
        ];
    }

    /** REF 1CF-SCHEDULING-DISPATCH-001 — Part "B" early-warning milestone. */
    private function scheduledEarlyWarningCopy(): array
    {
        /** @var Booking $b */
        $b = $this->subject;
        $service = $b->service?->name ?? 'Service';
        $when = $b->scheduled_at?->format('D, M j \a\t g:i A') ?? 'soon';

        return [
            'title' => 'Scheduled booking still unassigned',
            'body' => "Booking {$b->code} — {$service}, due {$when} — still has no provider. Early-warning threshold reached.",
        ];
    }

    /** REF 1CF-SCHEDULING-DISPATCH-001 — urgent milestone at scheduled_at minus the buffer. */
    private function scheduledUrgentAlertCopy(): array
    {
        /** @var Booking $b */
        $b = $this->subject;
        $service = $b->service?->name ?? 'Service';
        $when = $b->scheduled_at?->format('D, M j \a\t g:i A') ?? 'soon';

        return [
            'title' => 'URGENT: scheduled booking unassigned',
            'body' => "Booking {$b->code} — {$service}, due {$when} — is about to reach its scheduled time with no provider assigned. Needs immediate attention.",
        ];
    }

    /** REF 1CF-JOURNEY-001 — a job in progress has been flagged: the professional left / cannot continue. */
    private function jobAtRiskCopy(): array
    {
        /** @var Booking $b */
        $b = $this->subject;
        $service = $b->service?->name ?? 'Service';

        return [
            'title' => 'Job at risk: professional left',
            'body' => "Booking {$b->code} — {$service} — was flagged mid-work ({$b->hold_note}). Reassign it or cancel (no fee).",
        ];
    }

    /** MANUAL MONEY ACTIONS — a mismatch refund has waited too long at a lower level. */
    private function mismatchRefundEscalationCopy(): array
    {
        /** @var \App\Models\MismatchRefund $r */
        $r = $this->subject;

        return [
            'title' => 'Mismatch refund needs approval',
            'body' => '₹'.number_format($r->amountRupees(), 2)." mismatched payment has been waiting since {$r->created_at->format('d M, h:i A')}. Open the queue to act.",
        ];
    }

    /** A2/A3 — a pricing-dispute refund has waited too long at a lower level. */
    private function disputeRefundEscalationCopy(): array
    {
        /** @var \App\Models\BookingDispute $d */
        $d = $this->subject;

        return [
            'title' => 'Dispute refund needs approval',
            'body' => '₹'.number_format((float) $d->refund_amount, 2)." pricing-dispute refund has been waiting since {$d->refundClockStartedAt()->format('d M, h:i A')}. Open the queue to act.",
        ];
    }

    private function bookingCreatedCopy(): array
    {
        /** @var Booking $b */
        $b = $this->subject;
        $service = $b->service?->name ?? 'Service';
        $zone = $b->zone?->name ? " · {$b->zone->name}" : '';

        return [
            'title' => 'New booking',
            'body' => "Booking {$b->code} — {$service}{$zone} ({$b->status}).",
        ];
    }

    private function paymentCapturedCopy(): array
    {
        /** @var Payment $p */
        $p = $this->subject;
        $amount = '₹'.number_format((float) $p->amount, 2);
        $purpose = str_replace('_', ' ', (string) $p->purpose);
        $ref = $p->booking?->code ? " for {$p->booking->code}" : '';

        return [
            'title' => 'Payment captured',
            'body' => "{$amount} captured{$ref} ({$purpose}).",
        ];
    }
}
