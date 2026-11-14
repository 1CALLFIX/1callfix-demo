<?php

namespace App\Notifications;

use App\Models\HouseVisit;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Houses for rent — house_visit.owner_new to the owner (a push reaches them with the portal closed), and
 * house_visit.{accepted, declined, cancelled} to the customer. Same shape as the hall notifications.
 */
class HouseVisitNotification extends Notification
{
    use Queueable;

    public function __construct(private string $event, private HouseVisit $visit, private array $channels)
    {
    }

    public function via($notifiable): array
    {
        return $this->channels;
    }

    public function eventKey(): string
    {
        return "house_visit.{$this->event}";
    }

    private function copy(): array
    {
        $v = $this->visit;
        $home = $v->listing?->title ?? 'the home';
        $when = $v->visit_date?->format('D, j M').' · '.$v->slot;

        return match ($this->event) {
            'owner_new' => ['subject' => 'Someone wants to see your home', 'body' => "{$v->customer_name} wants to visit {$home} on {$when}. Accept or say no in your Rental portal (Visits)."],
            'owner_cancelled' => ['subject' => 'A visit was cancelled', 'body' => "{$v->customer_name} cancelled the visit to {$home} on {$when}."],
            'accepted' => ['subject' => 'Visit accepted', 'body' => "The owner accepted your visit to {$home} on {$when}.".($v->owner_note ? " Note: {$v->owner_note}" : '').' Open My home visits for the address and the owner\'s number.'],
            'declined' => ['subject' => 'The owner cannot show this home', 'body' => "The owner cannot show {$home} on {$when}.".($v->owner_note ? " Reason: {$v->owner_note}" : '').' Try another home or date.'],
            default => ['subject' => 'Home visit update', 'body' => "Your visit request {$v->code} was updated."],
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

    public function pushLink($notifiable): string
    {
        return str_starts_with($this->event, 'owner_') ? route('venue.visits') : route('customer.homes.visits');
    }
}
