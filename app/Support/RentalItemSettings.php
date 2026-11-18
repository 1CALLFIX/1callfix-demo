<?php

namespace App\Support;

/**
 * Rent anything (owner decision 2026-11-18, OLX-style): every choice a person taps and every limit, on Admin → Rental
 * Settings, following the cascade zone → franchise → city → country → global.
 *
 * Fixed rules (not settings): only people with a verified mobile number can post; the team approves every post before
 * customers see it; no money moves between customer and owner (call / WhatsApp only); an item named in the banned list
 * cannot be posted; enough open reports hide an item until the team checks it.
 * Customers see items only where Rental is on and the "Rent anything" kind is on (RentalKinds::ITEMS), zone by zone.
 */
class RentalItemSettings extends MarketplaceSettings
{
    public const REGISTRY = [
        // ── what a person can choose (per city / zone) ──
        'items.categories' => [
            'group' => 'Rent anything · Choices', 'label' => 'Kinds of item', 'type' => 'text',
            'default' => "Electronics and cameras\nSound and lights\nParty and event items\nFurniture\nHome appliances\nTools and machines\nGenerators and power\nSports and fitness\nBaby and kids\nBooks and music\nClothes and costumes\nOther",
            'help' => 'One per line. People tap one when posting; customers filter by them.',
        ],
        'items.price_units' => [
            'group' => 'Rent anything · Choices', 'label' => 'Price is per', 'type' => 'text',
            'default' => "Day\nHour\nWeek\nMonth\nEvent",
            'help' => 'One per line. People tap one next to their price.',
        ],
        'items.conditions' => [
            'group' => 'Rent anything · Choices', 'label' => 'Condition', 'type' => 'text',
            'default' => "New\nLike new\nGood\nUsed",
            'help' => 'One per line. Optional for people posting.',
        ],
        'items.banned' => [
            'group' => 'Rent anything · Choices', 'label' => 'Items that cannot be posted', 'type' => 'text',
            'default' => "Gun\nPistol\nRifle\nAmmunition\nExplosive\nFirecracker\nSword\nKnife\nDrug\nGanja\nAlcohol\nLiquor\nCigarette\nMedicine\nInjection\nPoison\nAnimal\nHuman\nCurrency\nFake",
            'help' => 'One word or phrase per line. A post whose name, kind or description has one of these is refused with a polite message.',
        ],
        // ── posting ──
        'items.max_live_per_person' => [
            'group' => 'Rent anything · Posting', 'label' => 'Items one person can have up at a time', 'type' => 'int', 'unit' => 'items', 'min' => 1, 'max' => 100,
            'default' => '3', 'help' => 'Counts items waiting for review and items that are up. Keeps checking easy for the team.',
        ],
        'items.min_photos' => [
            'group' => 'Rent anything · Posting', 'label' => 'Photos needed before sending for review', 'type' => 'int', 'unit' => 'photos (plus the cover)', 'min' => 0, 'max' => 8,
            'default' => '1', 'help' => 'Real photos of the item get far more calls.',
        ],
        'items.listing_days' => [
            'group' => 'Rent anything · Posting', 'label' => 'A post stays up for', 'type' => 'int', 'unit' => 'days', 'min' => 7, 'max' => 365,
            'default' => '30', 'help' => 'Then it is hidden until the person renews it.',
        ],
        'items.min_price' => [
            'group' => 'Rent anything · Posting', 'label' => 'Lowest price a person can enter', 'type' => 'int', 'unit' => '₹', 'min' => 0, 'max' => 100000,
            'default' => '20', 'help' => 'Stops typing mistakes.',
        ],
        'items.max_price' => [
            'group' => 'Rent anything · Posting', 'label' => 'Highest price a person can enter', 'type' => 'int', 'unit' => '₹', 'min' => 10, 'max' => 1000000,
            'default' => '50000', 'help' => 'Stops typing mistakes.',
        ],
        // ── safety ──
        'items.reports_to_hide' => [
            'group' => 'Rent anything · Safety', 'label' => 'Reports that hide an item until the team checks it', 'type' => 'int', 'unit' => 'reports', 'min' => 1, 'max' => 20,
            'default' => '3', 'help' => 'Each person can report an item once.',
        ],
        // ── contact ──
        'items.show_owner_phone' => [
            'group' => 'Rent anything · Contact', 'label' => 'Signed-in customers can see the owner\'s number', 'type' => 'switch', 'default' => '1',
            'help' => 'Off: the item page shows no number.',
        ],
        'items.phone_views_per_day' => [
            'group' => 'Rent anything · Contact', 'label' => 'Owner numbers a customer can see per day', 'type' => 'int', 'unit' => 'items', 'min' => 1, 'max' => 100,
            'default' => '10', 'help' => 'Stops people collecting numbers.',
        ],
    ];

    /** A list setting in this scope (each city / zone may have its own). @return list<string> */
    public static function listIn(string $key, array $scope = []): array
    {
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/', (string) self::get($key, $scope))), fn ($v) => $v !== '')));
    }
}
