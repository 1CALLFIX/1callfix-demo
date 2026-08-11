<?php

namespace App\Notifications\Channels;

use App\Contracts\PushAdapter;
use Illuminate\Notifications\Notification;

class PushChannel
{
    public function __construct(private PushAdapter $adapter)
    {
    }

    public function send($notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toPush')) {
            return;
        }

        $token = method_exists($notifiable, 'routeNotificationForPush')
            ? $notifiable->routeNotificationForPush($notification)
            : ($notifiable->fcm_token ?? null);

        if (! $token) {
            return;
        }

        ['title' => $title, 'body' => $body] = $notification->toPush($notifiable);

        $this->adapter->send($token, $title, $body);
    }
}
