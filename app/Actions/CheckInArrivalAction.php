<?php

namespace App\Actions;

use App\Models\Booking;
use App\Models\Provider;
use App\Services\ActivityLogger;
use App\Services\Cancellation\PolicySettings;
use App\Services\DispatchService;
use Illuminate\Support\Facades\DB;

/**
 * REF 1CF-CANCEL-POLICY-001 step 3 — "I have arrived", verified by GPS. Accepted only within
 * `cancellation.arrival_radius_meters` (from the booking's policy snapshot) of the booking address; the position and
 * time are stored on the booking. A visit charge can never be levied without this verified arrival. Idempotent.
 * The booking status does not change (the FSM is untouched): arrival is evidence on a `provider_en_route` booking.
 */
class CheckInArrivalAction
{
    public function __construct(private DispatchService $geo)
    {
    }

    /** @throws \RuntimeException */
    public function execute(int $bookingId, Provider $provider, float $lat, float $lng, ?int $userId = null): Booking
    {
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            throw new \RuntimeException('That location is not valid.');
        }

        return DB::transaction(function () use ($bookingId, $provider, $lat, $lng) {
            $booking = Booking::lockForUpdate()->findOrFail($bookingId);

            if ($booking->provider_id !== $provider->id) {
                throw new \RuntimeException('This booking is not assigned to you.');
            }
            if ($booking->arrival_verified_at !== null) {
                return $booking; // already checked in
            }
            if ($booking->status !== 'provider_en_route') {
                throw new \RuntimeException($booking->status === 'assigned'
                    ? 'Mark yourself on the way first, then check in when you arrive.'
                    : 'You can check in only while you are on the way to this job.');
            }

            $radius = PolicySettings::get($booking, 'cancellation.arrival_radius_meters');
            if ($radius === null) {
                throw new \RuntimeException('Arrival check-in is not set up yet — contact support.');
            }

            $address = $booking->address;
            if (! $address || $address->lat === null || $address->lng === null) {
                throw new \RuntimeException('This booking has no mapped address, so your arrival cannot be verified — contact support.');
            }

            $distance = (int) round($this->geo->haversineKm((float) $address->lat, (float) $address->lng, $lat, $lng) * 1000);
            if ($distance > (int) $radius) {
                throw new \RuntimeException("You are about {$distance} m from the address. Move within {$radius} m to check in.");
            }

            $booking->arrival_lat = $lat;
            $booking->arrival_lng = $lng;
            $booking->arrival_distance_m = $distance;
            $booking->arrival_verified_at = now();
            $booking->save();

            ActivityLogger::logModel($provider->user, $booking, 'provider arrived (GPS verified)', ['distance_m' => $distance, 'lat' => $lat, 'lng' => $lng]);

            return $booking->fresh();
        });
    }
}
