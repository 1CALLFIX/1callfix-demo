<?php

namespace App\Support;

/**
 * Vehicles for rent (owner 2026-11-18, OLX-style): every choice an owner taps and every limit, on Admin → Rental Settings,
 * following the settings cascade zone → franchise → city → country → global, so a hill town (jeeps, tractors) and a
 * coastal city (bikes, cars) each show their own words.
 *
 * Fixed rules (not settings): every listing is approved by the team before customers see it; the exact pickup address
 * is never shown, only the area; no money moves between customer and owner (the platform earns the listing fee).
 * Customers see vehicles only where Rental is on and the "Vehicles" kind is on (RentalKinds).
 */
class VehicleListingSettings extends MarketplaceSettings
{
    public const REGISTRY = [
        // ── joining ──
        'vehicles.recruiting_owners' => [
            'group' => 'Vehicles for rent · Joining', 'label' => 'Take vehicle owner sign-ups on /partners', 'type' => 'switch', 'default' => '0', 'global' => true,
            'help' => 'On: "Vehicle owner" on /partners opens the partner sign-up (mobile code, documents). Each vehicle waits for approval in Admin → Vehicles for rent.',
        ],
        // ── what an owner can choose (per city / zone) ──
        'vehicles.kinds' => [
            'group' => 'Vehicles for rent · Choices', 'label' => 'Kinds of vehicle', 'type' => 'text',
            'default' => "Car\nSUV / jeep\nBike / scooter\nAuto rickshaw\nVan / tempo traveller\nMini truck\nTruck / lorry\nTractor\nBus",
            'help' => 'One per line. Owners tap one; customers filter by them.',
        ],
        'vehicles.fuels' => [
            'group' => 'Vehicles for rent · Choices', 'label' => 'Fuel', 'type' => 'text',
            'default' => "Petrol\nDiesel\nCNG\nElectric\nPetrol + CNG",
            'help' => 'One per line.',
        ],
        'vehicles.transmissions' => [
            'group' => 'Vehicles for rent · Choices', 'label' => 'Gears', 'type' => 'text',
            'default' => "Manual\nAutomatic",
            'help' => 'One per line. Optional for owners.',
        ],
        'vehicles.driver_options' => [
            'group' => 'Vehicles for rent · Choices', 'label' => 'How it is rented', 'type' => 'text',
            'default' => "Self-drive\nWith driver",
            'help' => 'One per line. Owners tap all that apply; customers filter by them.',
        ],
        'vehicles.features' => [
            'group' => 'Vehicles for rent · Choices', 'label' => 'Features an owner can tick', 'type' => 'text',
            'default' => "Air conditioner\nMusic system\nGPS / navigation\nReverse camera\nFastag\nRoof carrier\nChild seat\nHelmet included\nFirst-aid kit\nGoods body / open deck\nAll-India permit\nFull insurance",
            'help' => 'One per line.',
        ],
        'vehicles.rules' => [
            'group' => 'Vehicles for rent · Choices', 'label' => 'Rules an owner can tick', 'type' => 'text',
            'default' => "Driving licence needed\nID proof needed\nNo smoking\nNo pets\nReturn with the same fuel\nFuel paid by customer\nToll and parking paid by customer\nDriver food and stay paid by customer",
            'help' => 'One per line.',
        ],
        // ── listing ──
        'vehicles.min_photos' => [
            'group' => 'Vehicles for rent · Listing', 'label' => 'Photos needed before sending for review', 'type' => 'int', 'unit' => 'photos (plus the cover)', 'min' => 0, 'max' => 10,
            'default' => '2', 'help' => 'Front, side and inside photos get far more calls.',
        ],
        'vehicles.listing_days' => [
            'group' => 'Vehicles for rent · Listing', 'label' => 'A listing stays up for', 'type' => 'int', 'unit' => 'days', 'min' => 7, 'max' => 365,
            'default' => '30', 'help' => 'Then it is hidden until the owner renews it (pays again when there is a fee).',
        ],
        'vehicles.min_price_day' => [
            'group' => 'Vehicles for rent · Listing', 'label' => 'Lowest price per day an owner can enter', 'type' => 'int', 'unit' => '₹ per day', 'min' => 0, 'max' => 100000,
            'default' => '200', 'help' => 'Stops typing mistakes.',
        ],
        'vehicles.max_price_day' => [
            'group' => 'Vehicles for rent · Listing', 'label' => 'Highest price per day an owner can enter', 'type' => 'int', 'unit' => '₹ per day', 'min' => 100, 'max' => 1000000,
            'default' => '50000', 'help' => 'Stops typing mistakes.',
        ],
        // ── listing fee (same engine as property) ──
        'vehicles.listing_fee' => [
            'group' => 'Vehicles for rent · Listing fee', 'label' => 'Fee to show a vehicle (each listing period)', 'type' => 'int', 'unit' => '₹', 'min' => 0, 'max' => 100000,
            'default' => '0', 'help' => '0 = free. Above 0: after the team approves a vehicle it goes live only when the owner pays this (online or from the wallet), and again to renew.',
        ],
        'vehicles.featured_fee' => [
            'group' => 'Vehicles for rent · Listing fee', 'label' => 'Fee to feature a vehicle (shown first)', 'type' => 'int', 'unit' => '₹', 'min' => 0, 'max' => 100000,
            'default' => '0', 'help' => '0 = owners cannot buy this. Above 0: a live vehicle is shown first on the lists for the days below.',
        ],
        'vehicles.featured_days' => [
            'group' => 'Vehicles for rent · Listing fee', 'label' => 'A paid feature lasts', 'type' => 'int', 'unit' => 'days', 'min' => 1, 'max' => 365,
            'default' => '7', 'help' => 'Then it goes back to the normal order.',
        ],
        // ── contact ──
        'vehicles.show_owner_phone' => [
            'group' => 'Vehicles for rent · Contact', 'label' => 'Signed-in customers can see the owner\'s number', 'type' => 'switch', 'default' => '1',
            'help' => 'Off: the vehicle page shows no number (use only while you decide how customers should reach owners).',
        ],
        'vehicles.phone_views_per_day' => [
            'group' => 'Vehicles for rent · Contact', 'label' => 'Owner numbers a customer can see per day', 'type' => 'int', 'unit' => 'vehicles', 'min' => 1, 'max' => 100,
            'default' => '10', 'help' => 'Stops people collecting numbers.',
        ],
    ];

    public static function recruitingOwners(): bool
    {
        return self::on('vehicles.recruiting_owners');
    }

    /** A list setting in this scope (each city / zone may have its own). @return list<string> */
    public static function listIn(string $key, array $scope = []): array
    {
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/', (string) self::get($key, $scope))), fn ($v) => $v !== '')));
    }
}
