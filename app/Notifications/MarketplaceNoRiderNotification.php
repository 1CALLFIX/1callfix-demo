<?php

namespace App\Notifications;

use App\Models\MarketplaceOrder;
use App\Notifications\Channels\PushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * "No rider accepted" (owner 2026-11-09): sent to the restaurant / shop and to admins watching operations once the
 * automatic rider search runs out, instead of the order sitting at "ready" with nobody told. Both can then pick a
 * nearby rider by hand from the order screen (OfferMarketplaceOrderToRiderAction).
 */
class MarketplaceNoRiderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private MarketplaceOrder $order, private string $link)
    {
    }

    public function via($notifiable): array
    {
        return [PushChannel::class];
    }

    public function eventKey(): string
    {
        return 'marketplace.no_rider';
    }

    public function pushLink($notifiable): string
    {
        return $this->link;
    }

    public function toPush($notifiable): array
    {
        return [
            'title' => 'No rider for '.$this->order->code,
            'body' => 'Open the order and pick a rider.',
        ];
    }
}
