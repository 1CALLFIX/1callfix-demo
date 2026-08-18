<?php

namespace App\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shared shape for GET /bookings/mine (list) and GET /bookings/{id}
 * (detail) — the same resource, detail fields simply appear when their
 * relation was eager-loaded (`whenLoaded`), so the list endpoint never
 * pays for or exposes more than it needs while the detail endpoint gets
 * the fuller picture, with no second resource class to keep in sync.
 *
 * Provider/worker exposure is deliberately minimal (name/phone/rating/
 * photo only) — Provider/FieldWorker carry real internal-only columns
 * (kyc_status, credit_balance, current_lat/lng, priority, ...) that have no
 * business reaching a customer's device. Status history is trimmed to
 * {status, changed_at} for the same reason — `BookingStatusHistory.note`
 * can carry internal admin phrasing ("Cancelled by admin: ...") and
 * `changed_by` is an internal actor id, neither appropriate here per the
 * mission's own "do not expose internal/admin-only data" instruction.
 */
class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'status' => $this->status,
            'scheduled_at' => $this->scheduled_at,
            'price_quoted' => $this->price_quoted !== null ? (float) $this->price_quoted : null,
            'price_final' => $this->price_final !== null ? (float) $this->price_final : null,
            'payment_status' => $this->payment_status,
            'payment_method' => $this->payment_method,
            'customer_note' => $this->customer_note,
            'cancellation_note' => $this->cancellation_note,
            'cancellation_fee' => $this->cancellation_fee !== null ? (float) $this->cancellation_fee : null,
            'created_at' => $this->created_at,
            'completed_at' => $this->completed_at,

            'service' => $this->whenLoaded('service', fn () => [
                'id' => $this->service->id,
                'name' => $this->service->name,
                'category' => $this->service->relationLoaded('category') ? $this->service->category?->name : null,
                'subcategory' => $this->service->relationLoaded('subcategory') ? $this->service->subcategory?->name : null,
            ]),

            'address' => $this->whenLoaded('address', fn () => $this->address ? [
                'id' => $this->address->id,
                'label' => $this->address->label,
                'address_line' => $this->address->address_line,
                'city' => $this->address->city,
            ] : null),

            'provider' => $this->when($this->provider_id && $this->relationLoaded('provider') && $this->provider?->user, fn () => [
                'name' => $this->provider->user->name,
                'phone' => $this->provider->user->phone,
                'rating_avg' => $this->provider->rating_avg !== null ? (float) $this->provider->rating_avg : null,
                'profile_photo' => $this->provider->user->profile_photo,
            ]),

            'worker' => $this->when($this->assigned_worker_id && $this->relationLoaded('assignedWorker') && $this->assignedWorker?->user, fn () => [
                'name' => $this->assignedWorker->user->name,
                'phone' => $this->assignedWorker->user->phone,
            ]),

            'status_history' => $this->whenLoaded('statusHistory', fn () => $this->statusHistory->map(fn ($h) => [
                'status' => $h->status,
                'changed_at' => $h->changed_at,
            ])),
        ];
    }
}
