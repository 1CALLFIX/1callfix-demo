<?php

namespace App\Notifications;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Covers plan.subscribed / plan.renewed / plan.renewal_failed / plan.expiry_reminder /
 * plan.cancelled / plan.expired / plan.failed — one event key, one
 * Subscription, per-event copy below. Same shape as every other
 * transactional Notification class in this app (BookingStatusNotification,
 * PaymentStatusNotification, PayoutStatusNotification, ...). Sent
 * synchronously (no ShouldQueue) so a verification run doesn't depend on a
 * queue worker actually running.
 */
class SubscriptionStatusNotification extends Notification
{
    use Queueable;

    public function __construct(private string $event, private Subscription $subscription, private array $channels)
    {
    }

    public function via($notifiable): array
    {
        return $this->channels;
    }

    public function eventKey(): string
    {
        return "plan.{$this->event}";
    }

    private function copy(): array
    {
        $planName = $this->subscription->plan?->name ?? 'your plan';
        $ends = $this->subscription->current_period_end
            ? app(\App\Services\TimezoneResolver::class)->format($this->subscription->current_period_end, null, 'j M Y')
            : null;

        return match ($this->event) {
            'subscribed' => [
                'subject' => 'Membership active',
                'body' => "Payment received — you're now subscribed to {$planName}".($ends ? " and it is valid until {$ends}." : '.'),
            ],
            'expiry_reminder' => [
                'subject' => 'Your membership ends soon',
                'body' => "{$planName} ends".($ends ? " on {$ends}" : ' soon').'. Renew to keep your benefits — unused benefits do not carry forward.',
            ],
            'renewed' => ['subject' => 'Subscription renewed', 'body' => "{$planName} has been renewed for another period."],
            'renewal_failed' => ['subject' => 'Renewal payment needed', 'body' => "We couldn't renew {$planName} automatically. Please complete payment to keep your benefits."],
            'cancelled' => ['subject' => 'Subscription cancelled', 'body' => "{$planName} has been cancelled and will not renew."],
            'expired' => ['subject' => 'Subscription expired', 'body' => "{$planName} has expired."],
            'failed' => ['subject' => 'Subscription payment failed', 'body' => "Payment for {$planName} failed. Your subscription was not activated."],
            default => ['subject' => 'Subscription update', 'body' => "{$planName} was updated."],
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
