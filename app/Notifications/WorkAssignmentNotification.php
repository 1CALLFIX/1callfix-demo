<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifies a FieldWorker's User when a Partner assigns them a booking.
 * Same shape as every other transactional Notification class in this app
 * (BookingStatusNotification, SubscriptionStatusNotification, ...) — no new
 * notification infrastructure.
 */
class WorkAssignmentNotification extends Notification
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
        return "worker.job_{$this->event}";
    }

    private function copy(): array
    {
        return match ($this->event) {
            'assigned' => ['subject' => 'New job assigned', 'body' => "You've been assigned booking {$this->booking->code}."],
            default => ['subject' => 'Job update', 'body' => "Booking {$this->booking->code} was updated."],
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
