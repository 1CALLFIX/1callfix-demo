<?php

namespace App\Actions;

use App\Events\ParcelOrderStatusUpdated;
use App\Models\FieldWorker;
use App\Models\ParcelOrder;
use App\Notifications\ParcelOrderStatusNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\DispatchService;
use App\Services\Parcel\ParcelSettings;
use App\Services\Parcel\ParcelWaitingCharge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Parcel Stage 1 (2026-10-25): the rider's "I've arrived" at the pickup point. Starts the waiting-charge clock
 * (ParcelWaitingCharge) and tells the sender. Only from the rider's live location, within
 * parcel.arrival_radius_km of the pickup pin, so the clock cannot start early. Tapping it is optional: a rider
 * who picks up without it simply charges no waiting. The order status does not change.
 */
class MarkParcelArrivedAtPickupAction
{
    public function __construct(private DispatchService $dispatch)
    {
    }

    public function execute(int $parcelOrderId, FieldWorker $worker, ?float $lat, ?float $lng): ParcelOrder
    {
        if ($lat === null || $lng === null) {
            throw new \RuntimeException("We couldn't read your location. Allow location access and try again.");
        }

        $justArrived = false;
        $order = DB::transaction(function () use ($parcelOrderId, $worker, $lat, $lng, &$justArrived) {
            $order = ParcelOrder::with('pickupAddress')->lockForUpdate()->findOrFail($parcelOrderId);

            if ($order->assigned_worker_id !== $worker->id) {
                throw new \RuntimeException('This parcel order is not assigned to you.');
            }
            if (! in_array($order->status, ['assigned', 'worker_en_route_pickup'], true)) {
                throw new \RuntimeException("You can only mark arrival before pickup (status '{$order->status}').");
            }
            if ($order->arrived_at_pickup_at) {
                return $order; // idempotent: a double tap never restarts the clock
            }

            $radius = (float) ParcelSettings::get('parcel.arrival_radius_km', ParcelWaitingCharge::scope($order));
            $away = $this->dispatch->haversineKm($lat, $lng, (float) $order->pickupAddress->lat, (float) $order->pickupAddress->lng);
            if ($away > $radius) {
                throw new \RuntimeException('You are '.round($away, 1).' km from the pickup point. Tap this when you reach it.');
            }

            $order->arrived_at_pickup_at = now();
            $order->save();

            $order->statusHistory()->create([
                'status' => $order->status,
                'changed_by' => $worker->user_id,
                'note' => 'Rider arrived at the pickup point',
                'changed_at' => now(),
            ]);

            $worker->forceFill(['current_lat' => $lat, 'current_lng' => $lng, 'location_updated_at' => now()])->save();

            event(new ParcelOrderStatusUpdated($order));

            $justArrived = true;

            return $order;
        });

        if ($justArrived && $order->customer) {
            try {
                $channels = ChannelResolver::resolve(['zone_id' => $order->zone_id, 'franchise_id' => $order->franchise_id]);
                $order->customer->notify(new ParcelOrderStatusNotification('arrived', $order, $channels));
            } catch (\Throwable $e) {
                Log::error("Failed to deliver parcel arrived notification for parcel order [{$order->id}]: ".$e->getMessage());
            }
        }

        return $order->fresh();
    }
}
