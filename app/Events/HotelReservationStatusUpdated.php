<?php

namespace App\Events;

use App\Models\HotelReservation;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// HOTEL / STAY BOOKING MODULE -- exact mirror of PropertyReservationStatusUpdated's shape.
class HotelReservationStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(public HotelReservation $reservation)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("hotel_reservation.{$this->reservation->id}")];
    }

    public function broadcastWith(): array
    {
        return [
            'hotel_reservation_id' => $this->reservation->id,
            'hotel_reservation_code' => $this->reservation->code,
            'status' => $this->reservation->status,
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
