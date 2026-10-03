<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Customer-facing notice when a captured payment's amount did not match
 * what was expected and the payment is held for review. The text is the
 * admin-editable `payments.mismatch_customer_message` setting
 * (AmountMismatchService::customerMessage()).
 */
class PaymentUnderReviewNotification extends Notification
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
        return 'payment.under_review';
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)->subject('Your payment is under review')->line($this->message);
    }

    public function toSms($notifiable): string
    {
        return $this->message;
    }

    public function toPush($notifiable): array
    {
        return ['title' => 'Payment under review', 'body' => $this->message];
    }

    public function toArray($notifiable): array
    {
        return ['title' => 'Payment under review', 'body' => $this->message];
    }
}
