<?php

namespace App\Notifications;

use App\Models\MarketingContact;
use App\Services\Marketing\ContactInviter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** App invitation email to an imported contact (owner 2026-11-21), with an unsubscribe link. Skips if unsubscribed meanwhile. */
class ContactAppInvite extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $contactId) {}

    public function via($notifiable): array
    {
        $contact = MarketingContact::find($this->contactId);

        return ($contact && ! $contact->unsubscribed_at) ? ['mail'] : [];
    }

    public function eventKey(): string
    {
        return 'marketing.app_invite';
    }

    public function toMail($notifiable): MailMessage
    {
        $contact = MarketingContact::findOrFail($this->contactId);
        $m = ContactInviter::message();
        $mail = (new MailMessage)->subject($m['title'])->greeting($contact->name ? 'Hi '.strtok($contact->name, ' ').',' : 'Hello,');
        foreach (preg_split('/\R{2,}/', $m['body']) as $para) {
            $mail->line(trim($para));
        }

        return $mail->action('Get the app', \App\Support\AppLinks::android())
            ->line('You are getting this because you used 1CallFix before. Do not want these emails? Unsubscribe: '.route('marketing.unsubscribe', $contact->unsubscribe_token));
    }
}
