<?php

namespace App\Events;

use App\Models\RentalReservation;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// RENTAL MODULE IMPLEMENTATION -- exact mirror of PropertyReservationStatusUpdated's shape, for the shared Vehicle/Equipment engine.
class RentalReservationStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(public RentalReservation $reservation)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("rental_reservation.{$this->reservation->id}")];
    }

    public function broadcastWith(): array
    {
        return [
            'rental_reservation_id' => $this->reservation->id,
            'rental_reservation_code' => $this->reservation->code,
            'status' => $this->reservation->status,
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
