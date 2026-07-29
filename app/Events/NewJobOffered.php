<?php

namespace App\Events;

use App\Models\Booking;
use App\Models\DispatchAttempt;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewJobOffered implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Booking $booking,
        public DispatchAttempt $dispatchAttempt,
    ) {
    }

    /**
     * Channel: provider.{id}.new-job — matches the naming pattern from the
     * architecture doc (and Glover's own channel-naming convention).
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("provider.{$this->dispatchAttempt->provider_id}.new-job"),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'booking_id' => $this->booking->id,
            'booking_code' => $this->booking->code,
            'service' => $this->booking->service->name,
            'distance_km' => $this->dispatchAttempt->distance_km,
            'price_quoted' => $this->booking->price_quoted,
            'address_line' => $this->booking->address->address_line,
            'scheduled_at' => $this->booking->scheduled_at,
            'expires_in_seconds' => 25, // must match ServiceMatchingJob::OFFER_TIMEOUT_SECONDS
        ];
    }
}
