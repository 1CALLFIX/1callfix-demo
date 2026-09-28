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
            // REF 1CF-SCHEDULING-DISPATCH-001 — a scheduled booking's offer
            // is OPEN (stays live until accepted/superseded/cancelled, see
            // ScheduledDispatchService), not subject to the ASAP
            // offer_timeout_seconds window at all. null tells a listening
            // client there is no countdown to show, rather than a
            // misleading fixed 25s. Reads the live Setting (previously
            // hardcoded 25, which could silently drift from an
            // admin-edited dispatch.offer_timeout_seconds).
            'expires_in_seconds' => $this->booking->scheduled_at
                ? null
                : (int) \App\Models\Setting::get('dispatch.offer_timeout_seconds', 25),
        ];
    }
}
