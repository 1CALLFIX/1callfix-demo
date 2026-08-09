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
 * these as boolean columns and predates the Car Rental addition, so it has
 * no `car_rental` column yet. That table is about *which franchise sells
 * what*, this list is about *what a category can belong to* — related but
 * not the same question, so they're allowed to differ. Worth reconciling
 * when Car Rental (P3) actually gets built.
 */
class Modules
{
    public const SERVICE = 'service';

    /** slug => display label, in rollout order */
    public const ALL = [
        'service' => 'Service',
        'parcel' => 'Parcel Delivery',
        'car_rental' => 'Car Rental',
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
