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
            'en_route' => ['subject' => "You're on the way", 'body' => "You marked job {$code} as on the way."],
            'started' => ['subject' => 'Job started', 'body' => "Job {$code} is now in progress."],
            'on_hold' => ['subject' => 'Job placed on hold', 'body' => "Job {$code} has been put on hold. Your dispatcher will be in touch."],
            'resumed' => ['subject' => 'Job resumed', 'body' => "Job {$code} is off hold and back in progress."],
            'completed' => ['subject' => 'Job completed', 'body' => "Job {$code} is complete. Your earnings have been added to your wallet."],
            'cancelled' => ['subject' => 'Job cancelled', 'body' => "Job {$code} has been cancelled."],
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
