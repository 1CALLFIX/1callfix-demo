<?php

namespace App\Events;

use App\Models\PropertyReservation;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// Phase 22.7 (Property Rental) -- exact mirror of ParcelOrderStatusUpdated/TaxiRideStatusUpdated's shape.
class PropertyReservationStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(public PropertyReservation $reservation)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("property_reservation.{$this->reservation->id}")];
    }

    public function broadcastWith(): array
    {
        return [
            'property_reservation_id' => $this->reservation->id,
            'property_reservation_code' => $this->reservation->code,
            'status' => $this->reservation->status,
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
