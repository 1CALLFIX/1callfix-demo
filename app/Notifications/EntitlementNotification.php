<?php

namespace App\Notifications;

use App\Models\PlanEntitlement;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Covers entitlement.consumed / entitlement.exhausted / entitlement.reversed. Same shape as every other transactional Notification class in this app. */
class EntitlementNotification extends Notification
{
    use Queueable;

    public function __construct(private string $event, private PlanEntitlement $entitlement, private array $channels)
    {
    }

    public function via($notifiable): array
    {
        return $this->channels;
    }

    public function eventKey(): string
    {
        return "entitlement.{$this->event}";
    }

    private function copy(): array
    {
        $planName = $this->entitlement->plan?->name ?? 'your plan';

        return match ($this->event) {
            'consumed' => ['subject' => 'Benefit used', 'body' => "A {$this->entitlement->entitlement_type} benefit from {$planName} was used on your last booking."],
            'exhausted' => ['subject' => 'Benefit quota used up', 'body' => "You've used your full {$this->entitlement->entitlement_type} quota from {$planName} for this period."],
            'reversed' => ['subject' => 'Benefit restored', 'body' => "A benefit from {$planName} was restored to your balance after a cancellation."],
            default => ['subject' => 'Plan benefit update', 'body' => "Your {$planName} benefits were updated."],
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
