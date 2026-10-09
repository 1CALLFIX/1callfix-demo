<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "Your shift starts in N minutes — go online." Same delivery shape as the other provider notifications. */
class ProviderShiftReminderNotification extends Notification
{
    use Queueable;

    public function __construct(private string $shiftName, private string $startsAt, private int $minutes, private array $channels)
    {
    }

    public function via($notifiable): array
    {
        return $this->channels;
    }

    public function eventKey(): string
    {
        return 'provider.shift_reminder';
    }

    private function copy(): array
    {
        return [
            'subject' => 'Your shift is about to start',
            'body' => "Your {$this->shiftName} shift starts at {$this->startsAt} (in about {$this->minutes} minutes). Open the app and go online so you can receive jobs.",
        ];
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
