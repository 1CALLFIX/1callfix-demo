<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BookingStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(public Booking $booking)
    {
    }

    /**
     * Channel: booking.{id} — a single channel both the customer app and the
     * assigned provider's app subscribe to for this specific booking.
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("booking.{$this->booking->id}")];
    }

    public function broadcastWith(): array
    {
        return [
            'booking_id' => $this->booking->id,
            'booking_code' => $this->booking->code,
            'status' => $this->booking->status,
            'provider_id' => $this->booking->provider_id,
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
