<?php

namespace App\Notifications;

use App\Models\MarketplaceOrder;
use App\Notifications\Channels\PushChannel;
use App\Support\DispatchQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Closed-app push to a rider offered a food or shop delivery (owner 2026-11-09: "only 1 ring on the rider app").
 * Before this, food and shop offers rang only while the rider page was open; parcel already pushed
 * (RiderParcelOfferNotification, same shape). Push only: an offer lives for seconds.
 */
class RiderShopOfferNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private MarketplaceOrder $order)
    {
    }

    public function via($notifiable): array
    {
        return [PushChannel::class];
    }

    public function viaQueues(): array
    {
        return [PushChannel::class => DispatchQueue::name()];
    }

    public function eventKey(): string
    {
        return 'rider.shop_offered';
    }

    /** Android apps ring kind=offer pushes on the loud offers channel. */
    public function pushKind(): string
    {
        return 'offer';
    }

    public function pushLink($notifiable): string
    {
        return route('rider.dashboard');
    }

    public function toPush($notifiable): array
    {
        $this->order->loadMissing('store');
        $food = $this->order->isFood();
        $place = $this->order->store?->name ?? ($food ? 'the restaurant' : 'the shop');

        return [
            'title' => $food ? 'New food delivery' : 'New shop delivery',
            'body' => "Pick up from {$place}. Open the rider app to accept before it expires.",
        ];
    }
}
