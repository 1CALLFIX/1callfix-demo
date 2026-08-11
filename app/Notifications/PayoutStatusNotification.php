<?php

namespace App\Notifications;

use App\Models\Payout;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Covers payout.paid / payout.failed — notifiable is the provider or
 * franchise owner User PayoutService already resolved.
 */
class PayoutStatusNotification extends Notification
{
    use Queueable;

    public function __construct(private string $event, private Payout $payout, private array $channels)
    {
    }

    public function via($notifiable): array
    {
        return $this->channels;
    }

    public function eventKey(): string
    {
        return "payout.{$this->event}";
    }

    private function copy(): array
    {
        $symbol = \App\Models\Setting::get('locale.currency_symbol', '₹');
        $amount = $symbol.number_format((float) $this->payout->amount, 2);

        return match ($this->event) {
            'paid' => ['subject' => 'Payout completed', 'body' => "Your payout of {$amount} has been paid (ref: {$this->payout->gateway_ref})."],
            'failed' => ['subject' => 'Payout failed', 'body' => "Your payout of {$amount} could not be completed and has been refunded to your wallet."],
            default => ['subject' => 'Payout update', 'body' => "Your payout of {$amount} was updated."],
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
