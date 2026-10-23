<?php

namespace App\Livewire\Rider\Concerns;

use App\Models\FieldWorker;
use App\Models\ParcelOrder;
use App\Services\ModuleActivationService;
use App\Support\Modules;

/** The signed-in rider (EnsureIsParcelRider has already proved one exists) and their franchise scope. */
trait InteractsWithRider
{
    protected function rider(): FieldWorker
    {
        return FieldWorker::with(['user', 'franchise'])->where('user_id', auth()->id())->firstOrFail();
    }

    protected function riderScope(FieldWorker $rider): array
    {
        return array_filter([
            'zone_id' => $rider->zone_id, 'franchise_id' => $rider->franchise_id,
            'city_id' => $rider->franchise?->city_id, 'country_id' => $rider->franchise?->country_id,
        ]);
    }

    /** Parcel switched on (Admin → Modules) where this rider works. */
    protected function parcelActiveFor(FieldWorker $rider): bool
    {
        return app(ModuleActivationService::class)->isActive(Modules::PARCEL, $this->riderScope($rider));
    }

    /** The rider's own order, or 404 — never another rider's. */
    protected function ownOrder(int $orderId, FieldWorker $rider): ParcelOrder
    {
        $order = ParcelOrder::with(['pickupAddress', 'dropoffAddress', 'customer', 'franchise'])->findOrFail($orderId);
        abort_unless($order->assigned_worker_id === $rider->id, 404);

        return $order;
    }
}
