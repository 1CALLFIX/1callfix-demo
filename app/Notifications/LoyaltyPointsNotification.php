<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Covers loyalty.earned / loyalty.redeemed.
 */
class LoyaltyPointsNotification extends Notification
{
    use Queueable;

    public function __construct(
        private string $event,
        private int $points,
        private int $newBalance,
        private array $channels,
        private ?float $rupeesCredited = null,
    ) {
    }

    public function via($notifiable): array
    {
        return $this->channels;
    }

    public function eventKey(): string
    {
        return "loyalty.{$this->event}";
    }

    private function copy(): array
    {
        $symbol = \App\Models\Setting::get('locale.currency_symbol', '₹');

        return match ($this->event) {
            'earned' => ['subject' => 'Points earned', 'body' => "You earned {$this->points} loyalty points. Balance: {$this->newBalance}."],
            'redeemed' => ['subject' => 'Points redeemed', 'body' => "You redeemed {$this->points} points for {$symbol}".number_format($this->rupeesCredited ?? 0, 2).". Balance: {$this->newBalance}."],
            default => ['subject' => 'Loyalty update', 'body' => "Your loyalty points balance is now {$this->newBalance}."],
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
