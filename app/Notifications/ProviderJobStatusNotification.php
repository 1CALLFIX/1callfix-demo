<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The provider-facing twin of BookingStatusNotification (which is written
 * for the customer). Same shape as every other transactional Notification
 * class in this app — WorkAssignmentNotification is the closest precedent
 * (also provider-side, also just per-event copy + the three channel
 * renderers) — so this adds no new notification infrastructure: it flows
 * through the identical via()/ChannelResolver/SmsChannel/PushChannel/
 * notification_logs pipeline the customer sends already use.
 *
 * Notifiable is the Provider's own User (Provider itself is not Notifiable
 * and has no fcm_token column — PushChannel's own docblock notes User is
 * the only Notifiable model with one today).
 *
 * Phase PN1 (foreground provider notifications). Covers the transitions a
 * provider needs to hear about on a job they hold:
 *   assigned   — an offer they accepted is now theirs
 *   en_route   — they marked themselves on the way (MarkEnRouteAction)
 *   started    — job moved to in_progress
 *   on_hold    — dispatcher/flow paused the job
 *   resumed    — job came back off hold
 *   completed  — job closed out, wallet credited
 *   cancelled  — job cancelled out from under them
 */
class ProviderJobStatusNotification extends Notification
{
    use Queueable;

    public function __construct(private string $event, private Booking $booking, private array $channels)
    {
    }

    public function via($notifiable): array
    {
        return $this->channels;
    }

    public function eventKey(): string
    {
        return "provider.job_{$this->event}";
    }

    private function copy(): array
    {
        $code = $this->booking->code;

        return match ($this->event) {
            'assigned' => ['subject' => 'Job assigned', 'body' => "You accepted job {$code}. Head to the customer and start with their start OTP."],
            // REF 1CF-SCHEDULING-DISPATCH-001 (Part 3) — a scheduled job's
            // acceptance confirmation names the date/time, service and
            // area so the provider knows exactly what they've committed
            // to, rather than "head to the customer now" (which reads as
            // an immediate ASAP dispatch, not a future appointment).
            'scheduled_assigned' => (function () use ($code) {
                $when = $this->booking->scheduled_at?->format('D, M j \a\t g:i A') ?? 'the scheduled time';
                $service = $this->booking->service?->name ?? 'Service';
                $area = $this->booking->address?->city ?? $this->booking->address?->label ?? 'your area';

                return ['subject' => 'Scheduled job confirmed', 'body' => "You're confirmed for {$service} on {$when} in {$area} (job {$code})."];
            })(),
            'reminder_60' => (function () use ($code) {
                $when = $this->booking->scheduled_at?->format('g:i A') ?? '';

                return ['subject' => 'Job coming up in 1 hour', 'body' => "Reminder: job {$code} is scheduled for {$when} — about an hour from now."];
            })(),
            'reminder_30' => (function () use ($code) {
                $when = $this->booking->scheduled_at?->format('g:i A') ?? '';

                return ['subject' => 'Job coming up in 30 minutes', 'body' => "Reminder: job {$code} is scheduled for {$when} — about 30 minutes from now."];
            })(),
            'en_route' => ['subject' => "You're on the way", 'body' => "You marked job {$code} as on the way."],
            'started' => ['subject' => 'Job started', 'body' => "Job {$code} is now in progress."],
            'on_hold' => ['subject' => 'Job placed on hold', 'body' => "Job {$code} has been put on hold. Your dispatcher will be in touch."],
            'resumed' => ['subject' => 'Job resumed', 'body' => "Job {$code} is off hold and back in progress."],
            'completed' => ['subject' => 'Job completed', 'body' => "Job {$code} is complete. Your earnings have been added to your wallet."],
            'cancelled' => ['subject' => 'Job cancelled', 'body' => "Job {$code} has been cancelled."],
            // REF 1CF-EXTRAWORK-001 / 1CF-JOURNEY-001
            'extra_work_approved' => ['subject' => 'Extra work approved', 'body' => "The customer approved your extra-work request on job {$code}. Carry on — the extra amount is paid to you on completion."],
            'extra_work_declined' => ['subject' => 'Extra work declined', 'body' => "The customer declined your extra-work request on job {$code}. The job continues at the original price."],
            'reassigned_to_you' => ['subject' => 'Job assigned to you', 'body' => "Job {$code} has been handed to you to continue. Head to the customer and start with their start OTP, then finish the work."],
            'reassigned_away' => ['subject' => 'Job moved to another professional', 'body' => "Job {$code} has been moved to another professional. No further action is needed from you."],
            // REF 1CF-CANCEL-POLICY-001
            'spares_delay_warning' => ['subject' => 'Spare part wait is getting long', 'body' => "Job {$code} is still waiting for spare parts. Once the wait reaches the limit the customer can cancel and you are paid only for the work done — chase the supplier or resume."],
            'spares_cancel_unlocked' => ['subject' => 'Customer can now cancel', 'body' => "Job {$code} has been waiting for spare parts too long; the customer can now cancel and pay only for the work already done. Get the part or resume to keep the job."],
            'spares_date_passed' => ['subject' => 'Spare part date passed', 'body' => "The expected spare-part date for job {$code} has passed. Open the job and enter a new expected date."],
            'spares_resume_overdue' => ['subject' => 'Resume the job', 'body' => "Spares for job {$code} are ready but the job has not resumed. The customer can now cancel free of charge and this affects your reliability score."],
            'spares_dispute' => ['subject' => 'Customer disputed your figures', 'body' => "The customer disputed the progress you declared on job {$code}. Our team will review it; the cancellation waits for that review."],
            'cancelled_with_charge' => ['subject' => 'Job cancelled — you are paid for work done', 'body' => "The customer cancelled job {$code}. Your share of the interim-work charge has been added to your wallet."],
            default => ['subject' => 'Job update', 'body' => "Job {$code} was updated."],
        };
    }

    public function toMail($notifiable): MailMessage
    {
        $copy = $this->copy();

        return (new MailMessage)->subject($copy['subject'])->line($copy['body']);
    }

    public function toSms($notifiable): string
    {
        return $this->copy()['body'];
    }

    public function toPush($notifiable): array
    {
        $copy = $this->copy();

        return ['title' => $copy['subject'], 'body' => $copy['body']];
    }
}
