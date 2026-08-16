<?php

namespace App\Notifications\Channels;

use App\Contracts\PushAdapter;
use App\Exceptions\InvalidPushTokenException;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

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

        try {
            $this->adapter->send($token, $title, $body);
        } catch (InvalidPushTokenException $e) {
            // The provider itself confirmed this token is dead
            // (uninstalled app/rotated token/never valid) -- clear it so
            // this user isn't pushed to a token that will only ever fail
            // again. LogPushAdapter (the default/dev binding) never throws
            // this, so current/default behavior is unchanged unless a real
            // push adapter is bound (BD-8). Scoped to User specifically --
            // the only Notifiable model with an fcm_token column today
            // (AUTHENTICATION_ARCHITECTURE.md's single-column limitation).
            Log::info('PushChannel: clearing invalid fcm_token.', ['notifiable' => get_class($notifiable), 'reason' => $e->getMessage()]);

            // Compare against the PROVIDER's own reported token (e->token),
            // not just the locally-captured $token var above -- a real
            // guard against clearing a newer token if the user
            // re-registered a device in the moments between this send()
            // and this exception being caught, not merely a comparison
            // against a value that was always going to match itself.
            if ($notifiable instanceof User && $notifiable->fcm_token === $e->token) {
                $notifiable->update(['fcm_token' => null]);
            }
        }
    }
}
