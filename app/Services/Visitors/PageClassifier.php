<?php

namespace App\Services\Visitors;

/**
 * Customer route name → what kind of page it is and which module it belongs to (Live Visitors, 2026-11-21).
 * Module keys are App\Support\ModuleSales keys (so the funnel's last step can use its order counts), plus
 * 'listings' for Rent anything and Second-hand (people's posts, no orders through us).
 *
 * A new customer page gets a line here; until then it is counted as 'other' (still a visitor, not in a funnel).
 */
final class PageClassifier
{
    /** route => module (null = no one module) */
    private const HOME = ['customer.home' => null, 'customer.city.show' => null];

    private const BROWSE = [
        'customer.services.index' => 'service', 'customer.offers' => 'service', 'customer.categories.index' => 'service',
        'customer.city.services' => 'service',
        'customer.city.parcel' => 'parcel',
        'customer.shop.home' => 'shop',
        'customer.food.home' => 'food',
        'customer.hotels.home' => 'hotel',
        'customer.events.home' => 'events',
        'customer.rentals.home' => 'rental', 'customer.halls.home' => 'rental', 'customer.homes.home' => 'rental', 'customer.vehicles.home' => 'rental',
        'customer.rent.home' => 'listings', 'customer.secondhand.home' => 'listings', 'customer.secondhand.used' => 'listings',
    ];

    private const ITEM = [
        'customer.city.item' => 'service', 'customer.bundles.show' => 'service',
        'customer.shop.store' => 'shop', 'customer.shop.product' => 'shop',
        'customer.food.restaurant' => 'food',
        'customer.hotels.show' => 'hotel',
        'customer.events.show' => 'events',
        'customer.halls.show' => 'rental', 'customer.homes.show' => 'rental', 'customer.vehicles.show' => 'rental',
        'customer.rent.show' => 'listings', 'customer.secondhand.show' => 'listings',
    ];

    /** Booking / checkout forms: the visitor has started to book. */
    private const CHECKOUT = [
        'customer.book' => 'service', 'customer.checkout' => 'service',
        'customer.shop.checkout' => 'shop', // food or shop: VisitTracker reads the store's module
        'customer.parcels.create' => 'parcel',
        'customer.hotels.book' => 'hotel',
        'customer.events.book' => 'events',
        'customer.halls.book' => 'rental',
    ];

    /** @return array{0: string, 1: ?string} [page type, module] */
    public static function classify(string $route): array
    {
        return match (true) {
            array_key_exists($route, self::HOME) => ['home', null],
            array_key_exists($route, self::ITEM) => ['item', self::ITEM[$route]],
            array_key_exists($route, self::BROWSE) => ['browse', self::BROWSE[$route]],
            array_key_exists($route, self::CHECKOUT) => ['checkout', self::CHECKOUT[$route]],
            $route === 'customer.cart' => ['cart', null],
            $route === 'customer.search' => ['search', null],
            default => ['other', null],
        };
    }

    /** Page types shown as "started to book". */
    public const BOOKING_TYPES = ['cart', 'checkout'];

    public const TYPE_LABELS = [
        'home' => 'Home', 'browse' => 'Browsing', 'item' => 'Viewing', 'search' => 'Searching',
        'cart' => 'In the cart', 'checkout' => 'Booking', 'other' => 'Other page',
    ];

    public const MODULE_LABELS = [
        'service' => 'Services', 'parcel' => 'Parcel', 'shop' => 'Shop', 'food' => 'Food', 'rental' => 'Rental',
        'hotel' => 'Hotel', 'events' => 'Events', 'listings' => 'Rent & second-hand', 'taxi' => 'Taxi',
    ];
}
