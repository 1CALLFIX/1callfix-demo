<?php

namespace App\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Phase E2 (Multi-Service Booking — Creation). The customer-facing shape of
 * a booking bundle and its child bookings.
 *
 *  - `status` is the stored latch (active/completed/cancelled); `derived_status`
 *    is BookingBundle::derivedStatus() — the read-only cross-child view built
 *    in E1, never written back.
 *  - `children` reuses BookingResource, so a child booking looks identical
 *    whether it was placed on its own or inside a bundle.
 *  - No idempotency_key / request_fingerprint / internal columns are exposed.
 */
class BookingBundleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'status' => $this->status,
            'derived_status' => $this->relationLoaded('children') ? $this->derivedStatus() : null,
            'payment_status' => $this->payment_status,
            'payment_method' => $this->payment_method,
            'total_price_quoted' => $this->total_price_quoted !== null ? (float) $this->total_price_quoted : null,
            'total_price_final' => $this->total_price_final !== null ? (float) $this->total_price_final : null,
            'created_at' => $this->created_at,

            'children' => BookingResource::collection($this->whenLoaded('children')),
        ];
    }
}
