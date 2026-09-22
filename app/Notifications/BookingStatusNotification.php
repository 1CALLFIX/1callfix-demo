<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Covers booking.created / booking.assigned / booking.completed /
 * booking.cancelled — one event key, one Booking, per-event copy below.
 * Sent synchronously (no ShouldQueue) so a test/verification run doesn't
 * depend on a queue worker actually running.
 */
class BookingStatusNotification extends Notification
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
        return "booking.{$this->event}";
    }

    private function copy(): array
    {
        return match ($this->event) {
            'created' => ['subject' => 'Booking confirmed', 'body' => "Your booking {$this->booking->code} has been received. We're finding a provider for you."],
            'assigned' => ['subject' => 'Provider assigned', 'body' => "A provider has been assigned to your booking {$this->booking->code}."],
            'completed' => ['subject' => 'Booking completed', 'body' => "Your booking {$this->booking->code} is complete. Thank you for using 1CallFix."],
            'cancelled' => ['subject' => 'Booking cancelled', 'body' => "Your booking {$this->booking->code} has been cancelled."],
            // REF 1CF-IMPLEMENT-20260922-L01 — the T+30 platform auto-
            // cancellation (DispatchDeadlineSweepService). Distinct from
            // 'cancelled' because "has been cancelled" alone reads as
            // something the customer or provider did; this booking was
            // cancelled by the platform after it couldn't be matched.
            // Wording deliberately avoids asserting "no providers were
            // available" specifically — that would misrepresent a genuine
            // queue/job failure (see ServiceMatchingJob::failed()) as a
            // supply problem, so it stays true under either cause.
            'no_provider_found' => ['subject' => 'Booking could not be completed', 'body' => "Your booking {$this->booking->code} could not be matched to a provider in time and has been cancelled. Any amount already paid has been refunded, and no cancellation fee applies."],
            default => ['subject' => 'Booking update', 'body' => "Your booking {$this->booking->code} was updated."],
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
