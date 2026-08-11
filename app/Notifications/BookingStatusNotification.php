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
