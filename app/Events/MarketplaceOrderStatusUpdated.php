<?php

namespace App\Events;

use App\Models\MarketplaceOrder;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// Phase 24 (Marketplace Foundation) -- exact mirror of PropertyReservationStatusUpdated's shape.
class MarketplaceOrderStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(public MarketplaceOrder $order)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("marketplace_order.{$this->order->id}")];
    }

    public function broadcastWith(): array
    {
        return [
            'marketplace_order_id' => $this->order->id,
            'marketplace_order_code' => $this->order->code,
            'status' => $this->order->status,
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
