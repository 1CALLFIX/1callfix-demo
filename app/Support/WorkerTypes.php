<?php

namespace App\Support;

/**
 * The canonical list of field-execution capability types a FieldWorker can
 * hold — one or more per worker (see field_worker_capabilities), never a
 * single restrictive column. Deliberately mirrors App\Support\Modules'
 * exact shape: a shared constant list rather than per-screen literals, and
 * a plain string set (not a DB enum) so a new capability type is a routine
 * addition here, not a destructive column migration — same reasoning
 * Modules and service_categories.module already established.
 *
 * Initial values are exactly the set approved in the Phase B0 architecture
 * report — no additional commercial worker types invented.
 */
class WorkerTypes
{
    /** slug => display label */
    public const ALL = [
        'service_technician' => 'Service Technician',
        'handyman' => 'Handyman',
        'helper' => 'Helper',
        'parcel_rider' => 'Parcel Rider',
        'taxi_driver' => 'Taxi Driver',
        'food_delivery_rider' => 'Food Delivery Rider',
        'grocery_delivery_rider' => 'Grocery Delivery Rider',
        /** Phase 24 (Marketplace Foundation) -- the two symmetric entries this list was already one module short of; food/grocery's own presence here (Phase B0.1, before Marketplace itself existed) is real evidence this exact reuse was anticipated. */
        'commerce_delivery_rider' => 'E-commerce Delivery Rider',
        'pharmacy_delivery_rider' => 'Pharmacy Delivery Rider',
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

    public static function isValid(string $slug): bool
    {
        return array_key_exists($slug, self::ALL);
    }
}
