<?php

namespace App\Notifications;

use App\Models\Setting;
use App\Models\TaxiRide;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Phase 22.6 (Taxi) — covers taxi_ride.{created,assigned,trip_started,
 * trip_completed,cancelled,refunded}, mirroring ParcelOrderStatusNotification's
 * exact shape (itself a mirror of BookingStatusNotification/
 * PaymentStatusNotification combined). A separate class, not a generalized
 * one shared across all three verticals — same "no forced abstraction"
 * reasoning as ParcelOrderStatusNotification's own docblock.
 */
class TaxiRideStatusNotification extends Notification
{
    use Queueable;

    public function __construct(
        private string $event,
        private TaxiRide $ride,
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
        return "taxi_ride.{$this->event}";
    }

    private function copy(): array
    {
        $symbol = Setting::get('locale.currency_symbol', '₹');

        return match ($this->event) {
            'created' => ['subject' => 'Ride requested', 'body' => "Your ride {$this->ride->code} has been requested. We're finding a driver for you."],
            'assigned' => ['subject' => 'Driver assigned', 'body' => "A driver has been assigned to your ride {$this->ride->code}."],
            'trip_started' => ['subject' => 'Trip started', 'body' => "Your ride {$this->ride->code} has started."],
            'trip_completed' => ['subject' => 'Trip completed', 'body' => "Your ride {$this->ride->code} is complete. Thank you for using 1CallFix."],
            'cancelled' => ['subject' => 'Ride cancelled', 'body' => "Your ride {$this->ride->code} has been cancelled."],
            'refunded' => ['subject' => 'Refund processed', 'body' => "A refund of {$symbol}".number_format($this->amount ?? 0, 2)." for ride {$this->ride->code} has been processed."],
            default => ['subject' => 'Ride update', 'body' => "Your ride {$this->ride->code} was updated."],
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
