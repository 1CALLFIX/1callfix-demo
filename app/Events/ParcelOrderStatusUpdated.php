<?php

namespace App\Events;

use App\Models\ParcelOrder;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// Phase 22.4 (Parcel) -- exact mirror of BookingStatusUpdated's shape.
class ParcelOrderStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(public ParcelOrder $order)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("parcel_order.{$this->order->id}")];
    }

    public function broadcastWith(): array
    {
        return [
            'parcel_order_id' => $this->order->id,
            'parcel_order_code' => $this->order->code,
            'status' => $this->order->status,
            'assigned_worker_id' => $this->order->assigned_worker_id,
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
