<?php

namespace App\Notifications\Channels;

use App\Contracts\WhatsAppAdapter;
use Illuminate\Notifications\Notification;

/**
 * Unified "All Users" Directory session, Part 2 (Bulk Notify) — completes
 * the channel set WhatsAppAdapter (Daily Digest session) only ever had a
 * direct-call consumer for (DailyDigestDispatchService). Exact mirror of
 * SmsChannel — same shape, same adapter-injection pattern — so CampaignNotification's
 * WhatsApp leg goes through the identical Notification/Channel/Adapter
 * pipeline every other channel already uses, not a special case.
 */
class WhatsAppChannel
{
    public function __construct(private WhatsAppAdapter $adapter)
    {
    }

    public function send($notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toWhatsApp')) {
            return;
        }

        $to = method_exists($notifiable, 'routeNotificationForWhatsApp')
            ? $notifiable->routeNotificationForWhatsApp($notification)
            : ($notifiable->phone ?? null);

        if (! $to) {
            return;
        }

        $this->adapter->send($to, $notification->toWhatsApp($notifiable));
    }
}
