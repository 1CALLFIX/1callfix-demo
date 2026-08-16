<?php

namespace App\Notifications;

use App\Models\ParcelOrder;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Phase 22.4 (Parcel) — covers parcel_order.{created,assigned,picked_up,
 * delivered,cancelled,refunded}, one event key, one ParcelOrder, per-event
 * copy below. Mirrors BookingStatusNotification's shape exactly (same
 * via()/eventKey()/toMail()/toSms()/toPush() structure) — deliberately a
 * separate class, not a generalized BookingStatusNotification accepting an
 * Orderable, for the same "no forced abstraction with a single real
 * consumer" reasoning CancellationService's own refundIfPaidForParcelOrder()
 * docblock explains. Combines what BookingStatusNotification and
 * PaymentStatusNotification separately cover for Service into one class
 * here, since Parcel's notification surface is smaller — $amount is only
 * ever set for the 'refunded' event, mirroring PaymentStatusNotification's
 * own optional-amount shape.
 */
class ParcelOrderStatusNotification extends Notification
{
    use Queueable;

    public function __construct(
        private string $event,
        private ParcelOrder $order,
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
        return "parcel_order.{$this->event}";
    }

    private function copy(): array
    {
        $symbol = Setting::get('locale.currency_symbol', '₹');

        return match ($this->event) {
            'created' => ['subject' => 'Parcel order confirmed', 'body' => "Your parcel order {$this->order->code} has been received. We're finding a rider for you."],
            'assigned' => ['subject' => 'Rider assigned', 'body' => "A rider has been assigned to your parcel order {$this->order->code}."],
            'picked_up' => ['subject' => 'Parcel picked up', 'body' => "Your parcel {$this->order->code} has been picked up and is on its way."],
            'delivered' => ['subject' => 'Parcel delivered', 'body' => "Your parcel {$this->order->code} has been delivered. Thank you for using 1CallFix."],
            'cancelled' => ['subject' => 'Parcel order cancelled', 'body' => "Your parcel order {$this->order->code} has been cancelled."],
            'refunded' => ['subject' => 'Refund processed', 'body' => "A refund of {$symbol}".number_format($this->amount ?? 0, 2)." for parcel order {$this->order->code} has been processed."],
            default => ['subject' => 'Parcel order update', 'body' => "Your parcel order {$this->order->code} was updated."],
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
