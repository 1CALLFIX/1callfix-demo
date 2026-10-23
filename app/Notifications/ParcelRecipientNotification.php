<?php

namespace App\Notifications;

use App\Models\ParcelOrder;
use App\Models\Setting;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\Support\ChannelResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/**
 * SMS to a parcel's recipient (owner decision 2026-10-23: deliver to anyone). The recipient is usually not a
 * 1CallFix user, so this goes to `recipient_phone` as an on-demand notifiable — SMS only, and only when SMS
 * is one of the location's configured channels (ChannelResolver). It carries the delivery OTP the recipient
 * gives the rider; the sender sees the same OTP on their order page either way.
 */
class ParcelRecipientNotification extends Notification
{
    use Queueable;

    public function __construct(private ParcelOrder $order)
    {
    }

    public static function sendFor(ParcelOrder $order): void
    {
        if (blank($order->recipient_phone) || blank($order->delivery_otp)) {
            return;
        }

        $channels = ChannelResolver::resolve(['zone_id' => $order->zone_id, 'franchise_id' => $order->franchise_id]);
        if (! in_array(SmsChannel::class, $channels, true)) {
            return;
        }

        NotificationFacade::route('sms', $order->recipient_phone)->notify(new self($order));
    }

    public function via($notifiable): array
    {
        return [SmsChannel::class];
    }

    public function eventKey(): string
    {
        return 'parcel_order.recipient_otp';
    }

    public function toSms($notifiable): string
    {
        $platform = Setting::get('branding.platform_name', '1CallFix');
        $sender = $this->order->customer?->name ?: 'someone';

        return "{$platform}: a parcel from {$sender} ({$this->order->code}) is on its way to you. "
            ."Give OTP {$this->order->delivery_otp} to the rider only when you receive it.";
    }
}
