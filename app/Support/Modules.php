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
 * future Car Rental vertical, evidence-permitting (see
 * `PHASE_23_HOTEL_AND_RENTAL_REQUIREMENTS_GAP.md` — that vertical remains
 * unbuilt, zero domain model, no new `car_rental` entry is added here
 * speculatively).
 */
class Modules
{
    public const SERVICE = 'service';
    public const PARCEL = 'parcel';
    public const TAXI = 'taxi';
    public const PROPERTY_RENTAL = 'property_rental';
    /** Phase 24 (Marketplace Foundation) -- these four slugs already existed in ALL below; adding real constants closes the same gap every prior vertical's own constant already avoided (a string literal instead of a named one). */
    public const FOOD = 'food';
    public const GROCERY = 'grocery';
    public const PHARMACY = 'pharmacy';
    public const COMMERCE = 'commerce';

    /** slug => display label, in rollout order */
    public const ALL = [
        'service' => 'Service',
        'parcel' => 'Parcel Delivery',
        'property_rental' => 'Property Rental',
        'food' => 'Food Delivery',
        'grocery' => 'Grocery',
        'pharmacy' => 'Pharmacy',
        'commerce' => 'E-commerce',
        'taxi' => 'Taxi Booking',
        'bookings' => 'Hotel Booking',
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
