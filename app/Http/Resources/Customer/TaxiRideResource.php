<?php

namespace App\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** TaxiRide counterpart to ParcelOrderResource — same conventions throughout. */
class TaxiRideResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'status' => $this->status,
            'distance_km' => $this->distance_km !== null ? (float) $this->distance_km : null,
            'price_quoted' => $this->price_quoted !== null ? (float) $this->price_quoted : null,
            'price_final' => $this->price_final !== null ? (float) $this->price_final : null,
            'payment_status' => $this->payment_status,
            'payment_method' => $this->payment_method,
            'customer_note' => $this->customer_note,
            'cancellation_note' => $this->cancellation_note,
            'cancellation_fee' => $this->cancellation_fee !== null ? (float) $this->cancellation_fee : null,
            'created_at' => $this->created_at,
            'trip_started_at' => $this->trip_started_at,
            'trip_completed_at' => $this->trip_completed_at,

            'pickup_address_id' => $this->pickup_address_id,
            'dropoff_address_id' => $this->dropoff_address_id,

            'pickup_address' => $this->whenLoaded('pickupAddress', fn () => $this->pickupAddress ? [
                'id' => $this->pickupAddress->id,
                'label' => $this->pickupAddress->label,
                'address_line' => $this->pickupAddress->address_line,
                'city' => $this->pickupAddress->city,
            ] : null),

            'dropoff_address' => $this->whenLoaded('dropoffAddress', fn () => $this->dropoffAddress ? [
                'id' => $this->dropoffAddress->id,
                'label' => $this->dropoffAddress->label,
                'address_line' => $this->dropoffAddress->address_line,
                'city' => $this->dropoffAddress->city,
            ] : null),

            'driver' => $this->when($this->assigned_worker_id && $this->relationLoaded('assignedWorker') && $this->assignedWorker?->user, fn () => [
                'name' => $this->assignedWorker->user->name,
                'phone' => $this->assignedWorker->user->phone,
                'rating_avg' => $this->assignedWorker->rating_avg !== null ? (float) $this->assignedWorker->rating_avg : null,
            ]),

            'status_history' => $this->whenLoaded('statusHistory', fn () => $this->statusHistory->map(fn ($h) => [
                'status' => $h->status,
                'changed_at' => $h->changed_at,
            ])),
        ];
    }
}
