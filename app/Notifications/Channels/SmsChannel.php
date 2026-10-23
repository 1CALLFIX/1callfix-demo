<?php

namespace App\Notifications\Channels;

use App\Contracts\SmsAdapter;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;

class SmsChannel
{
    public function __construct(private SmsAdapter $adapter)
    {
    }

    public function send($notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toSms')) {
            return;
        }

        // An on-demand recipient (Notification::route('sms', $phone) — e.g. a parcel recipient who is not a
        // user) carries its number as a route, not a property.
        $to = match (true) {
            method_exists($notifiable, 'routeNotificationForSms') => $notifiable->routeNotificationForSms($notification),
            $notifiable instanceof AnonymousNotifiable => $notifiable->routeNotificationFor('sms'),
            default => $notifiable->phone ?? null,
        };

        if (! $to) {
            return;
        }

        $this->adapter->send($to, $notification->toSms($notifiable));
    }
}
