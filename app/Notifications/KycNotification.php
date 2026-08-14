<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Covers every KYC/withdrawal-restriction lifecycle event — one class,
 * matching LoyaltyPointsNotification/PayoutStatusNotification's own
 * multi-event pattern rather than a notification class per event.
 */
class KycNotification extends Notification
{
    use Queueable;

    public function __construct(private string $event, private array $channels, private array $context = [])
    {
    }

    public function via($notifiable): array
    {
        return $this->channels;
    }

    public function eventKey(): string
    {
        return "kyc.{$this->event}";
    }

    private function copy(): array
    {
        return match ($this->event) {
            'deadline_reminder' => ['subject' => 'KYC completion reminder', 'body' => 'Please complete your KYC documents soon to avoid a withdrawal restriction.'],
            'deadline_warning' => ['subject' => 'KYC deadline approaching', 'body' => 'Your KYC completion deadline is approaching. Please submit any missing documents.'],
            'deadline_final_warning' => ['subject' => 'KYC deadline tomorrow', 'body' => 'Your KYC deadline is tomorrow. Withdrawals will be restricted if KYC is not completed in time.'],
            'withdrawal_restricted' => ['subject' => 'Withdrawals restricted', 'body' => 'Your KYC deadline has passed — withdrawals are temporarily restricted. You can continue accepting and completing jobs. Please complete your KYC or contact your Franchise Office.'],
            'withdrawal_restored' => ['subject' => 'Withdrawals restored', 'body' => 'Your withdrawal eligibility has been restored.'],
            'kyc_approved' => ['subject' => 'KYC approved', 'body' => 'Your KYC has been approved.'],
            'kyc_rejected' => ['subject' => 'KYC rejected', 'body' => 'Your KYC submission was rejected: '.($this->context['reason'] ?? 'see your account for details').'.'],
            'resubmission_required' => ['subject' => 'KYC resubmission required', 'body' => 'Please resubmit the requested KYC document(s).'],
            default => ['subject' => 'KYC update', 'body' => 'There is an update to your KYC status.'],
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
