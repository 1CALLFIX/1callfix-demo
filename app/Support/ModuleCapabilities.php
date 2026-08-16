<?php

namespace App\Support;

/**
 * Phase 22.3 (Module-Capability Contract). A documentation/data map, NOT a
 * runtime enforcement mechanism — there is exactly one real implemented
 * module (`service`) today, so building an interface/abstract-class
 * capability system now would be abstracting from a sample size of one,
 * which the mission brief's own "create reusable abstractions only where
 * real repetition exists" principle explicitly warns against. This map
 * exists so a future admin screen (e.g. conditionally hiding a "Dispatch
 * settings" tab for a module that doesn't need dispatch) has a real,
 * evidence-based source to read from the day it's built — not a forced
 * abstraction every module must awkwardly implement today.
 *
 * Every row's value is evidence-based: `service` reflects what the current
 * implementation actually has (verified against real code, not assumed);
 * every other module's row reflects PHASE_22_PLATFORM_CAPABILITY_RECOVERY_
 * AUDIT.md §4b's own findings about Glover's real per-vertical model
 * differences (e.g. Taxi's real-time matching vs. Property Rental's
 * calendar-availability model) — informed capability inventories, not
 * invented policy. `null` means "genuinely undetermined" (Hotel Booking has
 * no strong reference either in this codebase or in Glover — §5 of that
 * audit already flagged this), which this map represents honestly instead
 * of guessing.
 *
 * Capability keys, matching the mission brief's own list exactly:
 * catalog, order, pricing, payment, cancellation, dispatch, delivery,
 * provider, availability, scheduling, wallet, commission, settlement,
 * notifications, reviews, coupons, loyalty, referrals.
 */
class ModuleCapabilities
{
    private const ALL_KEYS = [
        'catalog', 'order', 'pricing', 'payment', 'cancellation', 'dispatch',
        'delivery', 'provider', 'availability', 'scheduling', 'wallet',
        'commission', 'settlement', 'notifications', 'reviews', 'coupons',
        'loyalty', 'referrals',
    ];

    /**
     * code => [capability => bool|null].  Only capabilities that differ
     * from the module-wide default of `false` are listed explicitly per
     * module; missing keys resolve to `false` via for($moduleCode).
     */
    public const MAP = [
        // Fully implemented (PHASE_22_PLATFORM_CAPABILITY_RECOVERY_AUDIT.md
        // §5, classification A). Every `true` here is verified against real
        // code (CatalogImporter, CreateBookingAction, DispatchService,
        // WalletService, CommissionService, PayoutService, NotificationCenter,
        // ReviewService). `coupons` is `true` because the schema/FK
        // integrity is real (KNOWN_RISKS_AND_DECISIONS.md item 7) even
        // though the customer-facing redemption path is dormant — capability
        // existing at the architecture level is the question this map
        // answers, not "is it launched."
        'service' => [
            'catalog' => true, 'order' => true, 'pricing' => true, 'payment' => true,
            'cancellation' => true, 'dispatch' => true, 'provider' => true,
            'scheduling' => true, // bookings.scheduled_at -- a simple field, not a slot/availability engine (see 'availability' => false below)
            'wallet' => true, 'commission' => true, 'settlement' => true,
            'notifications' => true, 'reviews' => true, 'coupons' => true,
            'loyalty' => true, 'referrals' => true,
            'delivery' => false, // a technician travels to the customer; nothing is delivered
            'availability' => false, // no calendar/slot inventory system exists -- scheduled_at is a single free-text-ish timestamp, not slot capacity management
        ],

        // Dispatch foundation exists (dispatch_attempts is polymorphic,
        // Phase B0.3) but zero operational module -- classification B.
        // Capabilities below are what a Parcel order will evidently need
        // once built (a delivery, not a technician visit), not a
        // commitment about how they'll be implemented.
        'parcel' => [
            'order' => true /* dispatch_attempts foundation only, no ParcelOrder yet */,
            'pricing' => null, 'payment' => null, 'cancellation' => null,
            'dispatch' => true, 'delivery' => true, 'provider' => true,
            'wallet' => null, 'commission' => null, 'settlement' => null, 'notifications' => null,
            'catalog' => false, // no product catalog concept -- a parcel isn't a cataloged item
            'availability' => false, 'scheduling' => false, 'reviews' => null, 'coupons' => null,
            'loyalty' => null, 'referrals' => null,
        ],

        // Classification D -- zero 1CallFix implementation. Glover's real
        // shared Vendor/Order shape (§4b) implies these, not invented.
        'commerce' => [
            'catalog' => true, 'order' => true, 'pricing' => true, 'payment' => true,
            'cancellation' => null, 'delivery' => true, 'provider' => true,
            'wallet' => null, 'commission' => null, 'settlement' => null, 'notifications' => null,
            'coupons' => null, 'loyalty' => null, 'referrals' => null, 'reviews' => null,
            'dispatch' => false, 'availability' => false, 'scheduling' => false,
        ],
        'food' => [
            'catalog' => true, 'order' => true, 'pricing' => true, 'payment' => true,
            'dispatch' => true, 'delivery' => true, 'provider' => true,
            'cancellation' => null, 'wallet' => null, 'commission' => null, 'settlement' => null,
            'notifications' => null, 'reviews' => null, 'coupons' => null, 'loyalty' => null, 'referrals' => null,
            'availability' => false, 'scheduling' => false,
        ],
        'grocery' => [
            'catalog' => true, 'order' => true, 'pricing' => true, 'payment' => true,
            'dispatch' => true, 'delivery' => true, 'provider' => true,
            'cancellation' => null, 'wallet' => null, 'commission' => null, 'settlement' => null,
            'notifications' => null, 'reviews' => null, 'coupons' => null, 'loyalty' => null, 'referrals' => null,
            'availability' => false, 'scheduling' => false,
        ],
        'pharmacy' => [
            'catalog' => true, 'order' => true, 'pricing' => true, 'payment' => true,
            'dispatch' => true, 'delivery' => true, 'provider' => true,
            // Prescription/document verification is a real, distinct concern
            // Glover/6amMart don't model either (KNOWN_RISKS_AND_DECISIONS.md
            // has no precedent for it) -- deliberately left null, not
            // assumed, per the mission brief's "do not invent regulatory
            // rules" instruction for this specific module.
            'cancellation' => null, 'wallet' => null, 'commission' => null, 'settlement' => null,
            'notifications' => null, 'reviews' => null, 'coupons' => null, 'loyalty' => null, 'referrals' => null,
            'availability' => false, 'scheduling' => false,
        ],
        'taxi' => [
            'order' => true, 'pricing' => true /* fare */, 'payment' => true,
            'dispatch' => true /* real-time, distinct from Service's book-ahead model -- Glover's own TaxiOrderMatchingJob family, §4b */,
            'provider' => true, 'cancellation' => null, 'wallet' => null, 'commission' => null,
            'settlement' => null, 'notifications' => null, 'reviews' => null,
            'catalog' => false, 'delivery' => false, 'availability' => false, 'scheduling' => false,
            'coupons' => null, 'loyalty' => null, 'referrals' => null,
        ],
        'bookings' => [ // Hotel Booking -- the least-evidenced module (§5); every value here is genuinely undetermined, not assumed.
            'catalog' => null, 'order' => null, 'pricing' => null, 'payment' => null,
            'cancellation' => null, 'availability' => null, 'scheduling' => null,
            'provider' => null, 'wallet' => null, 'commission' => null, 'settlement' => null,
            'notifications' => null, 'reviews' => null, 'coupons' => null, 'loyalty' => null, 'referrals' => null,
            'dispatch' => false, 'delivery' => false,
        ],
        // car_rental stands in for the Rental family (9A-9D) at the module-registry
        // level today -- App\Support\Modules::ALL has one 'car_rental' slug,
        // not four. Property (9A) is the one sub-type with a real Glover
        // reference (§4b); the other three are undetermined, matching §5.
        'car_rental' => [
            'catalog' => true /* Property/PropertyType, §4b */, 'order' => true,
            'pricing' => true, 'payment' => true, 'availability' => true /* PropertyAvailability, §4b */,
            'cancellation' => null, 'provider' => true /* owner */, 'wallet' => null, 'commission' => null,
            'settlement' => null, 'notifications' => null, 'reviews' => true /* PropertyReview, §4b */,
            'coupons' => null, 'loyalty' => null, 'referrals' => null,
            'dispatch' => false, 'delivery' => false, 'scheduling' => null,
        ],
    ];

    /** @return array<string, bool|null> capability => true/false/null(undetermined), every key from ALL_KEYS present */
    public static function for(string $moduleCode): array
    {
        $declared = self::MAP[$moduleCode] ?? [];

        $result = [];
        foreach (self::ALL_KEYS as $key) {
            // array_key_exists(), not ?? -- an explicit `null` (genuinely
            // undetermined, e.g. Hotel Booking's row) must be preserved,
            // not coalesced into `false` the way `??` would treat it.
            $result[$key] = array_key_exists($key, $declared) ? $declared[$key] : false;
        }

        return $result;
    }

    public static function keys(): array
    {
        return self::ALL_KEYS;
    }
}
