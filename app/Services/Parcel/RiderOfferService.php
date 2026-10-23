<?php

namespace App\Services\Parcel;

use App\Models\DispatchAttempt;
use App\Models\FieldWorker;
use App\Models\ParcelOrder;
use App\Models\Setting;
use Illuminate\Support\Collection;

/**
 * What the rider web area reads and the one thing it writes besides the job Actions: the rider's live parcel
 * offers (same predicate ParcelDispatchJob times out against) and their online/location status.
 */
class RiderOfferService
{
    /** @return Collection<int, DispatchAttempt> still-open offers for this rider, newest first, with their orders */
    public function liveOffers(FieldWorker $rider): Collection
    {
        $timeout = $this->offerTimeoutSeconds();

        return DispatchAttempt::where('dispatchable_type', ParcelOrder::class)
            ->where('notifiable_type', FieldWorker::class)
            ->where('notifiable_id', $rider->id)
            ->where('status', 'notified')
            ->where('notified_at', '>', now()->subSeconds($timeout))
            ->with('dispatchable.pickupAddress', 'dispatchable.dropoffAddress')
            ->latest('notified_at')
            ->get()
            ->filter(fn (DispatchAttempt $a) => $a->dispatchable && in_array($a->dispatchable->status, ['pending', 'searching_worker'], true) && $a->dispatchable->assigned_worker_id === null)
            ->values();
    }

    /** Display detail for the ring banner (resources/js/provider-alerts.js `provider-alert-offers` contract). */
    public function alertSummaries(Collection $offers): array
    {
        $symbol = Setting::get('locale.currency_symbol', '₹');
        $timeout = $this->offerTimeoutSeconds();

        return $offers->map(fn (DispatchAttempt $a) => [
            'id' => $a->dispatchable->id,
            'service' => 'Parcel '.ucfirst((string) $a->dispatchable->package_size),
            'code' => $a->dispatchable->code,
            'price' => $symbol.number_format((float) $a->dispatchable->price_quoted, 2),
            'distance' => $a->distance_km !== null ? rtrim(rtrim(number_format((float) $a->distance_km, 1), '0'), '.').' km to pickup' : null,
            'when' => 'Now',
            'area' => $a->dispatchable->pickupAddress?->address_line,
            'expires_in' => max(0, $timeout - (int) $a->notified_at->diffInSeconds(now())),
        ])->values()->all();
    }

    /** The rider's job in progress (one at a time — dispatch never offers a busy rider another). */
    public function activeJob(FieldWorker $rider): ?ParcelOrder
    {
        return ParcelOrder::where('assigned_worker_id', $rider->id)
            ->whereIn('status', ParcelOrder::ACTIVE_RIDER_STATUSES)
            ->with(['pickupAddress', 'dropoffAddress', 'customer'])
            ->latest('id')
            ->first();
    }

    /** Online with a server-stamped location fix, or offline. A missing fix keeps them online but unofferable. */
    public function setOnline(FieldWorker $rider, bool $online, ?float $lat = null, ?float $lng = null): void
    {
        $rider->is_online = $online;
        if ($online && $lat !== null && $lng !== null) {
            $rider->current_lat = $lat;
            $rider->current_lng = $lng;
            $rider->location_updated_at = now();
        }
        $rider->save();
    }

    public function offerTimeoutSeconds(): int
    {
        return (int) ParcelSettings::get('parcel.dispatch.offer_timeout_seconds');
    }
}
