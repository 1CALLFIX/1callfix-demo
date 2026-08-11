<?php

namespace App\Notifications\Channels;

use App\Contracts\SmsAdapter;
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

        $to = method_exists($notifiable, 'routeNotificationForSms')
            ? $notifiable->routeNotificationForSms($notification)
            : ($notifiable->phone ?? null);

        if (! $to) {
            return;
        }

        $this->adapter->send($to, $notification->toSms($notifiable));
    }
}
