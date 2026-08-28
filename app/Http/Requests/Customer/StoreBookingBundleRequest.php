<?php

namespace App\Http\Requests\Customer;

use App\Models\Setting;

/**
 * POST /api/booking-bundles (Phase E2 — multi-service booking creation).
 *
 * Mirrors StoreBookingRequest one-for-one on the per-service fields
 * (service_id / address_id / scheduled_at / customer_note) so the bundle
 * path and the single-service path can never diverge on what a customer may
 * submit or how far ahead a booking may be scheduled — `scheduled_at` reuses
 * the exact same `booking.max_schedule_days_ahead` Setting window.
 *
 * Deliberately does NOT accept `price` / `amount` / `total` / `price_quoted`
 * / `price_final` / `customer_id` / `franchise_id` / `zone_id` — every one of
 * those is server-derived (BookingBundleController::store + the authoritative
 * pricing engine), exactly as on POST /api/bookings.
 *
 * `payment_method` is bundle-level: a bundle is paid once, as a whole (one
 * aggregate wallet debit), so there is one method for all its children.
 *
 * Ownership of each `address_id`, whether each service is active, whether the
 * module is active for the address's scope, and whether the payment method is
 * enabled for that scope are all data-dependent checks the controller/Action
 * perform against the real rows — the same split StoreBookingRequest uses
 * (its address-ownership / service-active checks live in the controller too).
 */
class StoreBookingBundleRequest extends CustomerApiRequest
{
    /** A bundle is by definition multi-service; a single service belongs on POST /api/bookings. */
    public const MIN_SERVICES = 2;

    public const MAX_SERVICES = 20;

    public function rules(): array
    {
        $maxDays = (int) Setting::get('booking.max_schedule_days_ahead', 14);

        return [
            'payment_method' => ['nullable', 'string', 'in:online,cash,wallet'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],

            'services' => ['required', 'array', 'min:'.self::MIN_SERVICES, 'max:'.self::MAX_SERVICES],
            'services.*.service_id' => ['required', 'integer', 'exists:services,id'],
            'services.*.address_id' => ['required', 'integer', 'exists:addresses,id'],
            'services.*.scheduled_at' => [
                'nullable', 'date', 'after:now',
                'before_or_equal:'.now()->addDays($maxDays)->toDateTimeString(),
            ],
            'services.*.customer_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
