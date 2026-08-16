<?php

namespace App\Events;

use App\Models\TaxiRide;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// Phase 22.6 (Taxi) -- exact mirror of ParcelOrderStatusUpdated's shape.
class TaxiRideStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(public TaxiRide $ride)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("taxi_ride.{$this->ride->id}")];
    }

    public function broadcastWith(): array
    {
        return [
            'taxi_ride_id' => $this->ride->id,
            'taxi_ride_code' => $this->ride->code,
            'status' => $this->ride->status,
            'assigned_worker_id' => $this->ride->assigned_worker_id,
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
