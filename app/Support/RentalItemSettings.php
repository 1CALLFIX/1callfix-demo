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
            'default' => '1', 'help' => 'Real photos of the item get far more calls. Same for Second-hand.',
        ],
        'items.listing_days' => [
            'group' => 'Rent anything · Posting', 'label' => 'A post stays up for', 'type' => 'int', 'unit' => 'days', 'min' => 7, 'max' => 365,
            'default' => '30', 'help' => 'Then it is hidden until the person renews it. Same for Second-hand.',
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
            'default' => '3', 'help' => 'Each person can report an item once. Same for Second-hand.',
        ],
        // ── contact ──
        'items.show_owner_phone' => [
            'group' => 'Rent anything · Contact', 'label' => 'Signed-in customers can see the owner\'s number', 'type' => 'switch', 'default' => '1',
            'help' => 'Off: the item page shows no number. Same for Second-hand.',
        ],
        'items.phone_views_per_day' => [
            'group' => 'Rent anything · Contact', 'label' => 'Owner numbers a customer can see per day', 'type' => 'int', 'unit' => 'items', 'min' => 1, 'max' => 100,
            'default' => '10', 'help' => 'Stops people collecting numbers. Same for Second-hand.',
        ],

        // ── Second-hand (owner 2026-11-19): people sell used things on the same engine. Photos, days up, reports and
        // the owner's number use the Rent anything settings above; only these differ. ──
        'secondhand.enabled' => [
            'group' => 'Second-hand · Switch and choices', 'label' => 'Second-hand (people sell used things)', 'type' => 'switch', 'default' => '0',
            'help' => 'Off by default: turn on zone by zone. Anyone with a verified mobile number posts used things for sale; your team approves each post. Contact only: no payment runs through us.',
        ],
        'secondhand.categories' => [
            'group' => 'Second-hand · Switch and choices', 'label' => 'Kinds of used thing', 'type' => 'text',
            'default' => "Furniture\nHome appliances\nMobiles and tablets\nLaptops and computers\nTV and electronics\nKitchen items\nBicycles\nSports and fitness\nBaby and kids\nBooks\nTools\nOther",
            'help' => 'One per line. People tap one when posting; customers filter by them. Vehicles are not sold here (they need papers transferred).',
        ],
        'secondhand.conditions' => [
            'group' => 'Second-hand · Switch and choices', 'label' => 'Condition', 'type' => 'text',
            'default' => "Like new\nGood\nUsed\nNeeds repair",
            'help' => 'One per line. People tap one.',
        ],
        'secondhand.bill_kinds' => [
            'group' => 'Second-hand · Switch and choices', 'label' => 'Kinds that must have the bill (optional)', 'type' => 'text',
            'default' => null,
            'help' => 'Leave empty and the bill is never required (owner 2026-11-19): "I have the bill" is an optional tick, and customers see "🧾 Bill available" when it is ticked. Add a kind here (one per line, e.g. Mobiles and tablets) only if stolen things become a problem; for those kinds the tick is then needed before sending.',
        ],
        'secondhand.banned' => [
            'group' => 'Second-hand · Switch and choices', 'label' => 'Things that cannot be sold', 'type' => 'text',
            'default' => "Gun\nPistol\nRifle\nAmmunition\nExplosive\nFirecracker\nSword\nKnife\nDrug\nGanja\nAlcohol\nLiquor\nCigarette\nMedicine\nInjection\nPoison\nAnimal\nHuman\nCurrency\nFake\nReplica\nFirst copy\nStolen\nMotorcycle\nScooter\nScooty",
            'help' => 'One word or phrase per line. A post whose name, kind or description has one of these is refused with a polite message.',
        ],
        'secondhand.max_live_per_person' => [
            'group' => 'Second-hand · Posting', 'label' => 'Used things one person can have up at a time', 'type' => 'int', 'unit' => 'items', 'min' => 1, 'max' => 100,
            'default' => '5', 'help' => 'Counts things waiting for review and things that are up (sold ones do not count).',
        ],
        'secondhand.min_price' => [
            'group' => 'Second-hand · Posting', 'label' => 'Lowest price a person can enter', 'type' => 'int', 'unit' => '₹', 'min' => 0, 'max' => 100000,
            'default' => '50', 'help' => 'Stops typing mistakes.',
        ],
        'secondhand.max_price' => [
            'group' => 'Second-hand · Posting', 'label' => 'Highest price a person can enter', 'type' => 'int', 'unit' => '₹', 'min' => 10, 'max' => 10000000,
            'default' => '200000', 'help' => 'Stops typing mistakes.',
        ],

        // ── Paid packs (owner 2026-11-19): the same packs for rent and second-hand posts. Price 0 = that pack is off.
        // Posting itself stays free. Paid online (Razorpay) or from the person's wallet; HQ holds the money. ──
        'packs.featured_fee' => [
            'group' => 'Posts · Paid packs', 'label' => '⭐ Featured: price', 'type' => 'int', 'unit' => '₹', 'min' => 0, 'max' => 100000,
            'default' => '0', 'help' => 'Top of the list with a "Featured" tag. 0 = off.',
        ],
        'packs.featured_days' => [
            'group' => 'Posts · Paid packs', 'label' => '⭐ Featured: days', 'type' => 'int', 'unit' => 'days', 'min' => 1, 'max' => 90, 'default' => '7',
            'help' => 'How long a post stays Featured. Also used for each Featured from a bulk pack.',
        ],
        'packs.featured_normal_fee' => [
            'group' => 'Posts · Paid packs', 'label' => '⭐ Featured: normal price (optional)', 'type' => 'int', 'unit' => '₹', 'min' => 0, 'max' => 100000,
            'default' => '0', 'help' => 'Only during a real offer: shown crossed out next to the price. Leave 0 for no crossed-out price.',
        ],
        'packs.boost_fee' => [
            'group' => 'Posts · Paid packs', 'label' => '🚀 Boost: price', 'type' => 'int', 'unit' => '₹', 'min' => 0, 'max' => 100000,
            'default' => '0', 'help' => 'Everything in Featured, plus a big card in the "Boosted" row at the top of the page. 0 = off.',
        ],
        'packs.boost_days' => [
            'group' => 'Posts · Paid packs', 'label' => '🚀 Boost: days', 'type' => 'int', 'unit' => 'days', 'min' => 1, 'max' => 90, 'default' => '7',
            'help' => 'How long a post stays in the Boosted row (and Featured).',
        ],
        'packs.boost_normal_fee' => [
            'group' => 'Posts · Paid packs', 'label' => '🚀 Boost: normal price (optional)', 'type' => 'int', 'unit' => '₹', 'min' => 0, 'max' => 100000,
            'default' => '0', 'help' => 'Only during a real offer. Leave 0 for no crossed-out price.',
        ],
        'packs.boost_spots' => [
            'group' => 'Posts · Paid packs', 'label' => '🚀 Boost: spots per city at one time', 'type' => 'int', 'unit' => 'posts', 'min' => 1, 'max' => 20,
            'default' => '3', 'help' => 'Counted separately for rent and for second-hand. When all are taken, Boost shows when the next spot frees up.',
        ],
        'packs.bulk_small_count' => [
            'group' => 'Posts · Paid packs', 'label' => '📦 Small bulk pack: Featured posts in it', 'type' => 'int', 'unit' => 'posts', 'min' => 2, 'max' => 100, 'default' => '5',
            'help' => 'The pack card shows the saving against buying Featured one by one.',
        ],
        'packs.bulk_small_fee' => [
            'group' => 'Posts · Paid packs', 'label' => '📦 Small bulk pack: price', 'type' => 'int', 'unit' => '₹', 'min' => 0, 'max' => 1000000,
            'default' => '0', 'help' => 'One Featured is used on the post it is bought from; the rest on any of the person\'s posts later. 0 = off.',
        ],
        'packs.bulk_large_count' => [
            'group' => 'Posts · Paid packs', 'label' => '📦 Big bulk pack: Featured posts in it', 'type' => 'int', 'unit' => 'posts', 'min' => 2, 'max' => 500, 'default' => '10',
            'help' => 'Best for shops and people with many things to sell.',
        ],
        'packs.bulk_large_fee' => [
            'group' => 'Posts · Paid packs', 'label' => '📦 Big bulk pack: price', 'type' => 'int', 'unit' => '₹', 'min' => 0, 'max' => 1000000,
            'default' => '0', 'help' => '0 = off.',
        ],
        'packs.bulk_valid_days' => [
            'group' => 'Posts · Paid packs', 'label' => '📦 Bulk packs: use within', 'type' => 'int', 'unit' => 'days', 'min' => 7, 'max' => 365,
            'default' => '60', 'help' => 'Featured posts not used by then run out.',
        ],
        'packs.franchise_share_percent' => [
            'group' => 'Posts · Paid packs', 'label' => 'Franchise share of pack money', 'type' => 'int', 'unit' => '%', 'min' => 0, 'max' => 100,
            'default' => '0', 'help' => 'Worked out on each pack bought in the franchise\'s area and shown on People\'s posts. HQ holds the money; the franchise never handles it.',
        ],
    ];

    /** A list setting in this scope (each city / zone may have its own). @return list<string> */
    public static function listIn(string $key, array $scope = []): array
    {
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/', (string) self::get($key, $scope))), fn ($v) => $v !== '')));
    }
}
