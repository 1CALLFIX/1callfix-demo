<?php

namespace App\Support;

/**
 * The canonical list of verticals ("modules") the super app covers, in the
 * confirmed P1–P9 rollout order. Categories, subcategories and services all
 * hang off one of these — the equivalent of Glover's "vendor type" column
 * in the reference admin panel.
 *
 * Deliberately a single shared list rather than per-screen literals: the
 * Subcategories and Services screens need exactly the same dropdown, and
 * three drifting copies is how you end up with a category on a module the
 * services screen doesn't know exists.
 *
 * NOTE: `franchise_modules` (per-franchise on/off toggles) carries eight of
 * these as boolean columns and predates the Property Rental addition, so it
 * has no `property_rental` column yet. That table is about *which franchise
 * sells what*, this list is about *what a category can belong to* — related
 * but not the same question, so they're allowed to differ. Worth
 * reconciling if/when Property Rental (or a future real Car Rental) needs
 * that per-franchise toggle screen.
 *
 * **2026-08-17 slug rename:** this slug was `car_rental` from Phase 22.7
 * through Phase 25 — a real naming collision, not a cosmetic one: the slug
 * was actually carrying Property Rental's own module code the entire time
 * (`PropertyReservation::moduleCode()` returned it directly), while its
 * display label read "Car Rental." No real Car Rental (rentable-vehicle
 * inventory) implementation has ever existed anywhere in this codebase —
 * confirmed by a full-repository search before this rename — so nothing
 * real depended on the old slug meaning actual vehicle rental. Renamed to
 * `property_rental` to free the `car_rental` namespace for a genuine
 * future Car Rental vertical, evidence-permitting.
 *
 * **RENTAL MODULE IMPLEMENTATION:** renamed again, from `property_rental`
 * to `rental`. This is the real Rental vertical build — Property (existing,
 * preserved as-is), Vehicle and Equipment (new) — and the explicit product
 * decision is ONE top-level `rental` module covering all three
 * `rental_type` values, not three separate module-activation records
 * (`property_rental`/`vehicle_rental`/`equipment_rental`). Same in-place
 * `modules.code` update as the prior rename, same reasoning: the FK is the
 * integer `module_id`, never the code string, so nothing else needs
 * migrating.
 *
 * **HOTEL / STAY BOOKING MODULE (this phase):** the `'bookings'` slug
 * (label "Hotel Booking") was a real but completely dormant placeholder —
 * seeded into `modules` since Phase 22.1, zero consumer anywhere else in
 * the codebase (verified by grep before this rename). Renamed to `hotel`
 * for the real build, same safe in-place-rename precedent as the two
 * renames above. NOT reused as `bookings` because that string is already
 * heavily overloaded elsewhere in this codebase (the real `bookings` table/
 * `Booking` model for the Service vertical) — `hotel` has no such
 * collision. Hotel/Stay is explicitly its OWN top-level module, never
 * nested inside `rental` — see `HOTEL_MODULE_ARCHITECTURE.md` for the full
 * product-decision writeup: its inventory/reservation shape (room-type
 * quantity inventory, rate plans, multi-room bookings, guests distinct from
 * the booking owner) is fundamentally different from Property Rental's
 * single whole-unit date-range reservation.
 */
class Modules
{
    public const SERVICE = 'service';
    public const PARCEL = 'parcel';
    public const TAXI = 'taxi';
    public const RENTAL = 'rental';
    public const HOTEL = 'hotel';
    /** Phase 24 (Marketplace Foundation) -- these four slugs already existed in ALL below; adding real constants closes the same gap every prior vertical's own constant already avoided (a string literal instead of a named one). */
    public const FOOD = 'food';
    public const GROCERY = 'grocery';
    public const PHARMACY = 'pharmacy';
    public const COMMERCE = 'commerce';

    /** slug => display label, in rollout order */
    public const ALL = [
        'service' => 'Service',
        'parcel' => 'Parcel Delivery',
        'rental' => 'Rental',
        'food' => 'Food Delivery',
        'grocery' => 'Grocery',
        'pharmacy' => 'Pharmacy',
        'commerce' => 'E-commerce',
        'taxi' => 'Taxi Booking',
        'hotel' => 'Hotel / Stay',
    ];

    public static function options(): array
    {
        return self::ALL;
    }

    public static function slugs(): array
    {
        return array_keys(self::ALL);
    }

    public static function label(?string $slug): string
    {
        return self::ALL[$slug] ?? '—';
    }
}
