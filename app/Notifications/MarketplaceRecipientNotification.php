<?php

namespace App\Notifications;

use App\Models\MarketplaceOrder;
use App\Models\Setting;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\Support\ChannelResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/**
 * Shop checkout polish 2026-11-02: SMS to the person a shop order is sent to ("Send to someone else"). The same
 * pattern as ParcelRecipientNotification: the receiver is usually not a 1CallFix user, so this goes to
 * `recipient_phone` on demand — SMS only, and only where SMS is a configured channel. Sent when a rider accepts
 * (the delivery code exists from then on); it names the rider so the receiver can call them. The customer sees the
 * same code on their order page either way.
 */
class MarketplaceRecipientNotification extends Notification
{
    use Queueable;

    public function __construct(private MarketplaceOrder $order)
    {
    }

    public static function sendFor(MarketplaceOrder $order): void
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
        return 'marketplace_order.recipient_otp';
    }

    public function toSms($notifiable): string
    {
        $platform = Setting::get('branding.platform_name', '1CallFix');
        $sender = $this->order->customer?->name ?: 'someone';
        $shop = $this->order->store?->name ?: 'a shop';
        $rider = $this->order->assignedWorker?->user;
        $riderLine = $rider && filled($rider->phone) ? " Rider: {$rider->name}, {$rider->phone}." : '';

        return "{$platform}: {$sender} sent you an order from {$shop} ({$this->order->code}). It is on its way.{$riderLine} "
            ."Give OTP {$this->order->delivery_otp} to the rider only when you receive it.";
    }
}
