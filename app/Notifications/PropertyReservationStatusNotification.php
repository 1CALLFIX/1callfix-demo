<?php

namespace App\Notifications;

use App\Models\PropertyReservation;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Phase 22.7 (Property Rental) — covers property_reservation.{created,
 * confirmed,checked_in,completed,cancelled,refunded}, mirroring
 * ParcelOrderStatusNotification/TaxiRideStatusNotification's exact shape.
 */
class PropertyReservationStatusNotification extends Notification
{
    use Queueable;

    public function __construct(
        private string $event,
        private PropertyReservation $reservation,
        private array $channels,
        private ?float $amount = null
    ) {
    }

    public function via($notifiable): array
    {
        return $this->channels;
    }

    public function eventKey(): string
    {
        return "property_reservation.{$this->event}";
    }

    private function copy(): array
    {
        $symbol = Setting::get('locale.currency_symbol', '₹');

        return match ($this->event) {
            'created' => ['subject' => 'Reservation requested', 'body' => "Your reservation {$this->reservation->code} has been requested."],
            'confirmed' => ['subject' => 'Reservation confirmed', 'body' => "Your reservation {$this->reservation->code} is confirmed for {$this->reservation->check_in_date->format('d M Y')} to {$this->reservation->check_out_date->format('d M Y')}."],
            'checked_in' => ['subject' => 'Checked in', 'body' => "You're checked in for reservation {$this->reservation->code}."],
            'completed' => ['subject' => 'Stay completed', 'body' => "Your stay for reservation {$this->reservation->code} is complete. Thank you for using 1CallFix."],
            'cancelled' => ['subject' => 'Reservation cancelled', 'body' => "Your reservation {$this->reservation->code} has been cancelled."],
            'refunded' => ['subject' => 'Refund processed', 'body' => "A refund of {$symbol}".number_format($this->amount ?? 0, 2)." for reservation {$this->reservation->code} has been processed."],
            default => ['subject' => 'Reservation update', 'body' => "Your reservation {$this->reservation->code} was updated."],
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
