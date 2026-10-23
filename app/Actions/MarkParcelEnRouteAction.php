<?php

namespace App\Actions;

use App\Events\ParcelOrderStatusUpdated;
use App\Models\FieldWorker;
use App\Models\ParcelOrder;
use App\Notifications\ParcelOrderStatusNotification;
use App\Notifications\Support\ChannelResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The rider's "On my way to pickup" button: assigned → worker_en_route_pickup. The enum value has existed
 * since Phase 22.4 with no action behind it (PHASE_22_4_PARCEL_DESIGN.md, "finer-grained tracking states").
 * Optional for the rider — pickup is still allowed straight from 'assigned'.
 */
class MarkParcelEnRouteAction
{
    public function execute(int $parcelOrderId, FieldWorker $worker): ParcelOrder
    {
        $order = DB::transaction(function () use ($parcelOrderId, $worker) {
            $order = ParcelOrder::lockForUpdate()->findOrFail($parcelOrderId);

            if ($order->assigned_worker_id !== $worker->id) {
                throw new \RuntimeException('This parcel order is not assigned to you.');
            }
            if ($order->status !== 'assigned') {
                throw new \RuntimeException("Parcel order cannot be marked on the way from status '{$order->status}'.");
            }

            $order->status = 'worker_en_route_pickup';
            $order->save();

            $order->statusHistory()->create([
                'status' => 'worker_en_route_pickup',
                'changed_by' => $worker->user_id,
                'note' => 'Rider on the way to pickup',
                'changed_at' => now(),
            ]);

            event(new ParcelOrderStatusUpdated($order));

            return $order->fresh();
        });

        if ($order->customer) {
            try {
                $channels = ChannelResolver::resolve(['zone_id' => $order->zone_id, 'franchise_id' => $order->franchise_id]);
                $order->customer->notify(new ParcelOrderStatusNotification('en_route', $order, $channels));
            } catch (\Throwable $e) {
                Log::error("Failed to deliver parcel en_route notification for parcel order [{$order->id}]: ".$e->getMessage());
            }
        }

        return $order;
    }
}
