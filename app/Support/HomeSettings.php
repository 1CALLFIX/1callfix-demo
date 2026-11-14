<?php

namespace App\Support;

/**
 * Houses for rent (owner 2026-11-14: monthly house rent; "build each and every page with customizable options
 * controlled by superadmin to make it easy to scale for multi terrain, multi city, multi climatic conditions").
 *
 * Every list here (kinds of home, sizes, facilities, rules, deposit months, budget chips, visit times, area unit)
 * follows the settings cascade zone → franchise → city → country → global, so a hill town, a coastal city or a hot
 * plain can each have its own words and choices. Shown on Admin → Rental Settings.
 *
 * Fixed rules (not settings): every listing is approved by the team before customers see it; the exact address is
 * shown to a customer only after the owner accepts their visit; no money moves in this release.
 */
class HomeSettings extends MarketplaceSettings
{
    public const AREA_UNITS = ['sqft' => 'sq ft', 'sqm' => 'sq m', 'sqyd' => 'sq yd (gaj)'];

    public const REGISTRY = [
        // ── joining ──
        'homes.recruiting_owners' => [
            'group' => 'Houses for rent · Joining', 'label' => 'Take house owner sign-ups on /partners', 'type' => 'switch', 'default' => '0', 'global' => true,
            'help' => 'On: "House owner" on /partners opens the partner sign-up (mobile code, documents). Each listing waits for approval in Admin → Houses for rent.',
        ],
        // ── what an owner can choose (per city / zone) ──
        'homes.types' => [
            'group' => 'Houses for rent · Choices', 'label' => 'Kinds of home', 'type' => 'text',
            'default' => "Flat / apartment\nIndependent house\nVilla\nPortion of a house\nSingle room\nPG / hostel\nFarmhouse",
            'help' => 'One per line. Owners tap one; customers filter by them.',
        ],
        'homes.sizes' => [
            'group' => 'Houses for rent · Choices', 'label' => 'Sizes', 'type' => 'text',
            'default' => "1 RK\n1 BHK\n2 BHK\n3 BHK\n4 BHK\n5+ BHK",
            'help' => 'One per line, smallest first. Customers filter by them.',
        ],
        'homes.furnishing' => [
            'group' => 'Houses for rent · Choices', 'label' => 'Furnishing', 'type' => 'text',
            'default' => "Unfurnished\nSemi-furnished\nFully furnished",
            'help' => 'One per line.',
        ],
        'homes.tenants' => [
            'group' => 'Houses for rent · Choices', 'label' => 'Who the owner rents to', 'type' => 'text',
            'default' => "Family\nBachelor men\nBachelor women\nWorking professionals\nStudents\nCompany lease",
            'help' => 'One per line. Owners tap all that apply; customers filter by them.',
        ],
        'homes.facing' => [
            'group' => 'Houses for rent · Choices', 'label' => 'Facing', 'type' => 'text',
            'default' => "East\nWest\nNorth\nSouth\nNorth-East\nNorth-West\nSouth-East\nSouth-West",
            'help' => 'One per line. Optional for owners. Empty = not asked.',
        ],
        'homes.floors' => [
            'group' => 'Houses for rent · Choices', 'label' => 'Floor', 'type' => 'text',
            'default' => "Ground floor\n1st floor\n2nd floor\n3rd floor\n4th floor\n5th floor or higher\nWhole building",
            'help' => 'One per line. Optional for owners.',
        ],
        'homes.amenities' => [
            'group' => 'Houses for rent · Choices', 'label' => 'Facilities an owner can tick', 'type' => 'text',
            'default' => "Water:\n24-hour water\nMunicipal water\nBorewell\nOverhead tank\nRO drinking water\nPower:\nPower backup\nInverter\nSolar water heater\nSeparate electricity meter\nWeather and climate:\nAir conditioner\nCeiling fans\nGeyser / hot water\nGood sunlight\nCross ventilation\nMosquito mesh on windows\nHeat-proof roof\nNo water logging in rain\nRoom heater\nInside the home:\nModular kitchen\nWardrobes\nGas pipeline\nWashing machine space\nBalcony\nPooja room\nAttached bathrooms\nParking:\nCar parking\nTwo-wheeler parking\nCovered parking\nSafety:\nGated community\nSecurity guard\nCCTV\nFire extinguisher\nTerrain and access:\nLift\nVehicle reaches the door\nOn a tar road\nNo steps to climb\nWheelchair access\nNearby:\nBus stop\nSchool\nHospital\nMarket\nPark",
            'help' => 'One per line. A line ending with ":" is a section (Water:, Weather and climate:, Terrain and access:, ...). Set a different list for a hill town, a coastal city or a cold place on that franchise or zone.',
        ],
        'homes.rules' => [
            'group' => 'Houses for rent · Choices', 'label' => 'House rules an owner can tick', 'type' => 'text',
            'default' => "Pets allowed\nNo pets\nNon-veg allowed\nVegetarian only\nNo smoking\nNo alcohol\nGuests allowed\nGate closes at 10 PM\nAgreement for 11 months\nOne month notice to leave",
            'help' => 'One per line. Shown on the home page before a customer asks to visit.',
        ],
        'homes.deposit_months' => [
            'group' => 'Houses for rent · Choices', 'label' => 'Deposit buttons (months of rent)', 'type' => 'text',
            'default' => "1\n2\n3\n6\n10",
            'help' => 'One number per line. The owner taps one and the deposit is filled in (they can still type their own). Cities differ, e.g. 10 months in Bengaluru.',
        ],
        'homes.budget_steps' => [
            'group' => 'Houses for rent · Choices', 'label' => 'Budget buttons for customers (rent per month, up to)', 'type' => 'text',
            'default' => "5000\n10000\n15000\n25000\n50000",
            'help' => 'One amount per line, smallest first. Customers tap "Up to ₹10,000". Set per city.',
        ],
        'homes.area_unit' => [
            'group' => 'Houses for rent · Choices', 'label' => 'Unit for the size of a home', 'type' => 'choice',
            'options' => self::AREA_UNITS, 'default' => 'sqft',
            'help' => 'What owners type and customers see.',
        ],
        // ── listing ──
        'homes.min_photos' => [
            'group' => 'Houses for rent · Listing', 'label' => 'Photos needed before sending for review', 'type' => 'int', 'unit' => 'photos (plus the cover)', 'min' => 0, 'max' => 10,
            'default' => '3', 'help' => 'Homes with good photos get far more visits.',
        ],
        'homes.listing_days' => [
            'group' => 'Houses for rent · Listing', 'label' => 'A listing stays up for', 'type' => 'int', 'unit' => 'days', 'min' => 7, 'max' => 365,
            'default' => '60', 'help' => 'Then it is hidden until the owner taps "Still available". Keeps rented homes off the list.',
        ],
        'homes.min_rent' => [
            'group' => 'Houses for rent · Listing', 'label' => 'Lowest rent an owner can enter', 'type' => 'int', 'unit' => '₹ per month', 'min' => 0, 'max' => 100000,
            'default' => '1000', 'help' => 'Stops typing mistakes.',
        ],
        'homes.max_rent' => [
            'group' => 'Houses for rent · Listing', 'label' => 'Highest rent an owner can enter', 'type' => 'int', 'unit' => '₹ per month', 'min' => 1000, 'max' => 10000000,
            'default' => '500000', 'help' => 'Stops typing mistakes.',
        ],
        // ── contact and visits ──
        'homes.show_owner_phone' => [
            'group' => 'Houses for rent · Contact and visits', 'label' => 'Signed-in customers can see the owner\'s number', 'type' => 'switch', 'default' => '1',
            'help' => 'Off: customers can only ask for a visit, and see the number once the owner accepts.',
        ],
        'homes.phone_views_per_day' => [
            'group' => 'Houses for rent · Contact and visits', 'label' => 'Owner numbers a customer can see per day', 'type' => 'int', 'unit' => 'homes', 'min' => 1, 'max' => 100,
            'default' => '10', 'help' => 'Stops people collecting numbers.',
        ],
        'homes.visit_slots' => [
            'group' => 'Houses for rent · Contact and visits', 'label' => 'Visit times a customer can pick', 'type' => 'text',
            'default' => "Morning (9 AM – 12 PM)\nAfternoon (12 – 4 PM)\nEvening (4 – 8 PM)",
            'help' => 'One per line.',
        ],
        'homes.visit_days_ahead' => [
            'group' => 'Houses for rent · Contact and visits', 'label' => 'Visits can be asked for up to', 'type' => 'int', 'unit' => 'days ahead', 'min' => 1, 'max' => 60,
            'default' => '14', 'help' => '',
        ],
        'homes.visits_per_day' => [
            'group' => 'Houses for rent · Contact and visits', 'label' => 'Visit requests a customer can send per day', 'type' => 'int', 'unit' => 'requests', 'min' => 1, 'max' => 50,
            'default' => '5', 'help' => 'Stops spam to owners.',
        ],
    ];

    public static function recruitingOwners(): bool
    {
        return self::on('homes.recruiting_owners');
    }

    /** A list setting in this scope (each city / zone may have its own). */
    public static function listIn(string $key, array $scope = []): array
    {
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/', (string) self::get($key, $scope))), fn ($v) => $v !== '')));
    }

    /** @return list<array{title: string, items: list<string>}> facilities in their sections, for this scope */
    public static function amenityGroups(array $scope = []): array
    {
        $groups = [];
        $i = -1;
        foreach (preg_split('/\R/', (string) self::get('homes.amenities', $scope)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (str_ends_with($line, ':')) {
                $groups[++$i] = ['title' => rtrim(mb_substr($line, 0, -1)), 'items' => []];

                continue;
            }
            if ($i < 0) {
                $groups[++$i] = ['title' => '', 'items' => []];
            }
            if (! in_array($line, $groups[$i]['items'], true)) {
                $groups[$i]['items'][] = $line;
            }
        }

        return array_values(array_filter($groups, fn ($g) => $g['items'] !== []));
    }

    /** @return list<string> */
    public static function amenityList(array $scope = []): array
    {
        return array_values(array_merge([], ...array_map(fn ($g) => $g['items'], self::amenityGroups($scope))));
    }

    /** @return list<int> whole numbers from a one-per-line list (deposit months, budget steps) */
    public static function numbersIn(string $key, array $scope = []): array
    {
        $n = array_map('intval', array_filter(self::listIn($key, $scope), fn ($v) => ctype_digit($v)));
        sort($n);

        return array_values(array_unique(array_filter($n, fn ($v) => $v > 0)));
    }

    public static function areaUnit(array $scope = []): string
    {
        return self::AREA_UNITS[self::get('homes.area_unit', $scope)] ?? 'sq ft';
    }

    public static function validate(string $key, mixed $value): ?string
    {
        if (in_array($key, ['homes.deposit_months', 'homes.budget_steps'], true) && $value !== null && trim((string) $value) !== '') {
            foreach (preg_split('/\R/', trim((string) $value)) as $line) {
                if (trim($line) !== '' && ! ctype_digit(trim($line))) {
                    return "Only whole numbers, one per line: {$line}";
                }
            }
        }

        return parent::validate($key, $value);
    }
}
