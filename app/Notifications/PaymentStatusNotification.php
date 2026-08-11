<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Covers payment.completed / payment.failed / payment.refunded.
 */
class PaymentStatusNotification extends Notification
{
    use Queueable;

    public function __construct(private string $event, private Booking $booking, private array $channels, private ?float $amount = null)
    {
    }

    public function via($notifiable): array
    {
        return $this->channels;
    }

    public function eventKey(): string
    {
        return "payment.{$this->event}";
    }

    private function copy(): array
    {
        $symbol = \App\Models\Setting::get('locale.currency_symbol', '₹');

        return match ($this->event) {
            'completed' => ['subject' => 'Payment received', 'body' => "We've received your payment for booking {$this->booking->code}."],
            'failed' => ['subject' => 'Payment failed', 'body' => "Your payment for booking {$this->booking->code} could not be processed. Please try again."],
            'refunded' => ['subject' => 'Refund processed', 'body' => "A refund of {$symbol}".number_format($this->amount ?? 0, 2)." for booking {$this->booking->code} has been processed."],
            default => ['subject' => 'Payment update', 'body' => "Payment status updated for booking {$this->booking->code}."],
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
