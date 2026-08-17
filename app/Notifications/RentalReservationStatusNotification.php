<?php

namespace App\Notifications;

use App\Models\RentalReservation;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * RENTAL MODULE IMPLEMENTATION -- covers
 * rental_reservation.{created,confirmed,picked_up,active,returned,
 * completed,cancelled,refunded}, mirroring
 * PropertyReservationStatusNotification's exact shape for the shared
 * Vehicle/Equipment engine.
 */
class RentalReservationStatusNotification extends Notification
{
    use Queueable;

    public function __construct(
        private string $event,
        private RentalReservation $reservation,
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
        return "rental_reservation.{$this->event}";
    }

    private function copy(): array
    {
        $symbol = Setting::get('locale.currency_symbol', '₹');

        return match ($this->event) {
            'created' => ['subject' => 'Rental requested', 'body' => "Your rental reservation {$this->reservation->code} has been requested."],
            'confirmed' => ['subject' => 'Rental confirmed', 'body' => "Your rental reservation {$this->reservation->code} is confirmed."],
            'picked_up' => ['subject' => 'Pickup recorded', 'body' => "Pickup for rental reservation {$this->reservation->code} has been recorded."],
            'active' => ['subject' => 'Rental started', 'body' => "Your rental reservation {$this->reservation->code} is now active."],
            'returned' => ['subject' => 'Return recorded', 'body' => "Return for rental reservation {$this->reservation->code} has been recorded."],
            'completed' => ['subject' => 'Rental completed', 'body' => "Your rental reservation {$this->reservation->code} is complete. Thank you for using 1CallFix."],
            'cancelled' => ['subject' => 'Rental cancelled', 'body' => "Your rental reservation {$this->reservation->code} has been cancelled."],
            'refunded' => ['subject' => 'Refund processed', 'body' => "A refund of {$symbol}".number_format($this->amount ?? 0, 2)." for rental reservation {$this->reservation->code} has been processed."],
            default => ['subject' => 'Rental update', 'body' => "Your rental reservation {$this->reservation->code} was updated."],
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
