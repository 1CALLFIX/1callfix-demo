<?php

namespace App\Notifications;

use App\Models\EventOrder;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Events E3 — event_order.{confirmed, event_cancelled, cancelled}: tickets ready, the organiser cancelled the event
 * (full refund), or the buyer cancelled (refund of the ticket price). Same shape as the hotel notification.
 */
class EventOrderStatusNotification extends Notification
{
    use Queueable;

    public function __construct(
        private string $event,
        private EventOrder $order,
        private array $channels,
        private ?float $amount = null,
    ) {
    }

    public function via($notifiable): array
    {
        return $this->channels;
    }

    public function eventKey(): string
    {
        return "event_order.{$this->event}";
    }

    private function copy(): array
    {
        $symbol = Setting::get('locale.currency_symbol', '₹');
        $title = $this->order->event?->title ?? 'your event';
        $money = $symbol.number_format($this->amount ?? 0, 2);

        return match ($this->event) {
            'confirmed' => ['subject' => 'Your tickets are ready', 'body' => "Your {$this->order->ticket_count} ticket(s) for {$title} are ready (order {$this->order->code}). Open My tickets and show the QR code at the entrance."],
            'event_cancelled' => ['subject' => 'Event cancelled: full refund', 'body' => "{$title} has been cancelled by the organiser. {$money} for order {$this->order->code} is being refunded to where you paid from."],
            'cancelled' => ['subject' => 'Tickets cancelled', 'body' => "Your tickets for {$title} (order {$this->order->code}) are cancelled. {$money} is being refunded to where you paid from."],
            default => ['subject' => 'Ticket update', 'body' => "Your tickets for {$title} (order {$this->order->code}) were updated."],
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
