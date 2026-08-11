<?php

namespace App\Notifications;

use App\Models\Referral;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Covers referral.rewarded (the only referral event with a real notify
 * point today -- referral.created has no counterpart action for the
 * referrer to be told about yet since it's just a pending row).
 */
class ReferralStatusNotification extends Notification
{
    use Queueable;

    public function __construct(private string $event, private Referral $referral, private array $channels)
    {
    }

    public function via($notifiable): array
    {
        return $this->channels;
    }

    public function eventKey(): string
    {
        return "referral.{$this->event}";
    }

    private function copy(): array
    {
        $symbol = \App\Models\Setting::get('locale.currency_symbol', '₹');
        $referredName = $this->referral->referred?->name ?? 'your referral';

        return match ($this->event) {
            'rewarded' => (float) $this->referral->reward_amount > 0
                ? ['subject' => 'Referral reward earned', 'body' => "{$referredName} completed their first booking. You've earned {$symbol}".number_format($this->referral->reward_amount, 2)."."]
                : ['subject' => 'Referral reward earned', 'body' => "{$referredName} completed their first booking. Your referral reward has been credited."],
            default => ['subject' => 'Referral update', 'body' => "Your referral status has been updated."],
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
