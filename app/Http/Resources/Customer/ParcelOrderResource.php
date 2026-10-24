<?php

namespace App\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shared shape for POST /parcel-orders, GET /parcel-orders/mine, GET
 * /parcel-orders/{id}, and the cancel response — same "detail fields
 * appear only when their relation was eager-loaded" convention
 * `BookingResource` established in P0. `pickup_address_id`/
 * `dropoff_address_id` are always included (not just the nested summary)
 * specifically so a client can drive a "Book Again" quote/create call
 * directly from a history row, per the mission's own repeat-readiness
 * requirement, without a second lookup.
 */
class ParcelOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'status' => $this->status,
            'package_description' => $this->package_description,
            'package_weight_kg' => $this->package_weight_kg !== null ? (float) $this->package_weight_kg : null,
            'package_size' => $this->package_size,
            'price_quoted' => $this->price_quoted !== null ? (float) $this->price_quoted : null,
            'price_final' => $this->price_final !== null ? (float) $this->price_final : null,
            'payment_status' => $this->payment_status,
            'payment_method' => $this->payment_method,
            // Cash only: pickup = the sender pays the rider at pickup, drop = the recipient pays the rider at delivery.
            'cash_collect_at' => $this->cash_collect_at ?? 'pickup',
            'customer_note' => $this->customer_note,
            'pickup_contact_name' => $this->pickup_contact_name,
            'pickup_contact_phone' => $this->pickup_contact_phone,
            'recipient_name' => $this->recipient_name,
            'recipient_phone' => $this->recipient_phone,
            'distance_km' => $this->distance_km !== null ? (float) $this->distance_km : null,
            'price_breakdown' => $this->price_breakdown,
            'awaiting_payment' => $this->resource->isAwaitingOnlinePayment(),
            'can_cancel' => $this->resource->canBeCancelledByCustomer(),
            // Only ever served to the order's own customer (every endpoint checks ownership). The sender gives
            // the pickup OTP to the rider and passes the delivery OTP to the recipient.
            // 'disputed' = the recipient refused to pay at drop; the sender gives the pickup code back to close the return.
            'pickup_otp' => in_array($this->status, ['assigned', 'worker_en_route_pickup', 'disputed'], true) ? $this->pickup_otp : null,
            'delivery_otp' => in_array($this->status, ['assigned', 'worker_en_route_pickup', 'picked_up', 'en_route_dropoff'], true) ? $this->delivery_otp : null,
            'cancellation_note' => $this->cancellation_note,
            'cancellation_fee' => $this->cancellation_fee !== null ? (float) $this->cancellation_fee : null,
            'created_at' => $this->created_at,
            'picked_up_at' => $this->picked_up_at,
            'delivered_at' => $this->delivered_at,

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

            // Minimal, customer-safe rider exposure -- same fields/reasoning
            // as BookingResource's provider block (no kyc_status/credit_
            // balance/live location).
            'rider' => $this->when($this->assigned_worker_id && $this->relationLoaded('assignedWorker') && $this->assignedWorker?->user, fn () => [
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
