<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Security notice to an admin's OLD email address after their login email or
 * password changed. Sent on demand (Notification::route('mail', $oldEmail)):
 * after an email change the account itself no longer points at the old
 * address. Deliberately not queued — it must not depend on the worker — and
 * the caller guards it so a mail failure never blocks the change itself.
 * Never contains a password.
 */
class AdminLoginChangedNotification extends Notification
{
    /** @param  string  $what  'email address' | 'password' */
    public function __construct(private string $message, private string $what, private string $when)
    {
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your 1CallFix admin login details were changed')
            ->line($this->message)
            ->line("What changed: {$this->what}")
            ->line("When: {$this->when}");
    }
}
