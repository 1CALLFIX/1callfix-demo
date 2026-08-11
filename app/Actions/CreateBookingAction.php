<?php

namespace App\Actions;

use App\Jobs\ServiceMatchingJob;
use App\Models\Booking;
use App\Models\Service;
use App\Notifications\BookingStatusNotification;
use App\Notifications\Support\ChannelResolver;

class CreateBookingAction
{
    /**
     * Creates a booking (booking.code is auto-filled by BookingObserver) and
     * immediately queues the dispatch job. This is the entry point M3 hangs off —
     * every booking, from the customer app or a Tinker test alike, goes through here.
     */
    public function execute(array $data): Booking
    {
        $service = Service::findOrFail($data['service_id']);

        $booking = Booking::create([
            'franchise_id' => $data['franchise_id'],
            'zone_id' => $data['zone_id'],
            'customer_id' => $data['customer_id'],
            'service_id' => $service->id,
            'address_id' => $data['address_id'],
            'status' => 'pending',
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'price_quoted' => $data['price_quoted'] ?? $service->base_price,
            'payment_method' => $data['payment_method'] ?? 'online',
            'customer_note' => $data['customer_note'] ?? null,
        ]);

        ServiceMatchingJob::dispatch($booking->id);

        if ($booking->customer) {
            $channels = ChannelResolver::resolve(['zone_id' => $booking->zone_id, 'franchise_id' => $booking->franchise_id]);
            $booking->customer->notify(new BookingStatusNotification('created', $booking, $channels));
        }

        return $booking;
    }
}
