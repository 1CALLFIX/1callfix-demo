<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Customer-facing notice once a mismatched-payment refund has actually gone
 * through the gateway. The text is the Super Admin-editable
 * `refund.mismatch.notice_refunded` setting with [amount] filled in
 * (AmountMismatchService::refundedMessage()).
 */
class RefundProcessedNotification extends Notification
{
    use Queueable;

    public function __construct(private string $message, private array $channels)
    {
    }

    public function via($notifiable): array
    {
        return $this->channels;
    }

    public function eventKey(): string
    {
        return 'payment.refund_processed';
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)->subject('Your refund has been processed')->line($this->message);
    }

    public function toSms($notifiable): string
    {
        return $this->message;
    }

    public function toPush($notifiable): array
    {
        return ['title' => 'Refund processed', 'body' => $this->message];
    }

    public function toArray($notifiable): array
    {
        return ['title' => 'Refund processed', 'body' => $this->message];
    }
}
