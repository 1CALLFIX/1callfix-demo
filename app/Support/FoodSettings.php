<?php

namespace App\Support;

/**
 * Food delivery — every restaurant value Super Admin sets on Admin → Food Settings (owner rule 2026-11-02: every
 * activity is controlled from Super Admin). Same types, cascade (zone → franchise → city → country → global),
 * validation and audit log as Shop Settings; only the keys differ. Shop values that also apply to food (unpaid
 * expiry, rider payout, dispatch rounds, commission rules, tax) stay on Shop Settings: one engine, one setting.
 */
class FoodSettings extends MarketplaceSettings
{
    public const REGISTRY = [
        // ── restaurants ──
        'food.recruiting_restaurants' => [
            'group' => 'Restaurants', 'label' => 'Take restaurant sign-ups on /partners', 'type' => 'switch', 'default' => '0', 'global' => true,
            'help' => 'On: the "Restaurant" card on /partners opens the partner sign-up (mobile code, documents, FSSAI number). Each restaurant waits for approval in Admin → Stores. Turn on before Food opens to customers, so menus are ready.',
        ],
        'food.fssai_required' => [
            'group' => 'Restaurants', 'label' => 'FSSAI licence needed before a restaurant is approved', 'type' => 'switch', 'default' => '1',
            'help' => 'On (default): a restaurant cannot be approved, or take orders, without an FSSAI licence number on its page. The law in India needs it for every food business; keep it on.',
        ],
        'food.cuisines' => [
            'group' => 'Restaurants', 'label' => 'Cuisines a restaurant can choose', 'type' => 'text', 'global' => true,
            'default' => "South Indian\nAndhra\nNorth Indian\nBiryani\nChinese\nFast food\nPizza & burgers\nBakery & sweets\nIce cream & desserts\nJuices & beverages",
            'help' => 'One per line. Restaurants pick up to 3 from this list; customers filter by them.',
        ],
        'food.packaging_charge_max' => [
            'group' => 'Restaurants', 'label' => 'Highest packaging charge a restaurant may add', 'type' => 'decimal', 'unit' => '₹', 'min' => 0, 'max' => 500,
            'default' => null, 'help' => 'Per order. Blank = restaurants cannot add a packaging charge.',
        ],
        // ── kitchen timing ──
        'food.default_prep_minutes' => [
            'group' => 'Kitchen timing', 'label' => 'Usual cooking time when the restaurant has not set one', 'type' => 'int', 'unit' => 'minutes', 'min' => 5, 'max' => 120,
            'default' => '20', 'help' => 'Used for the delivery time shown to customers and for when a rider is called.',
        ],
        'food.busy_max_extra_minutes' => [
            'group' => 'Kitchen timing', 'label' => 'Most extra time a busy restaurant can add', 'type' => 'int', 'unit' => 'minutes', 'min' => 0, 'max' => 120,
            'default' => '30', 'help' => 'The restaurant\'s "Busy" button adds 10, 20 … minutes up to this. 0 = no busy button.',
        ],
        'food.busy_max_hours' => [
            'group' => 'Kitchen timing', 'label' => 'Busy mode switches itself off after', 'type' => 'int', 'unit' => 'hours', 'min' => 1, 'max' => 12,
            'default' => '2', 'help' => 'So a restaurant that forgets to switch it off goes back to normal times.',
        ],
        'food.rider_call_before_ready_minutes' => [
            'group' => 'Kitchen timing', 'label' => 'Call a rider this long before the food is ready', 'type' => 'int', 'unit' => 'minutes', 'min' => 0, 'max' => 60,
            'default' => null, 'help' => 'The rider reaches the restaurant as the food is packed, so it arrives hot. The rider can only take it once the restaurant marks it ready. Blank = call the rider only when the food is ready (like shops).',
        ],
        // ── delivery ──
        'food.delivery_radius_km' => [
            'group' => 'Delivery', 'label' => 'Restaurants deliver up to', 'type' => 'decimal', 'unit' => 'km', 'min' => 1, 'max' => 50,
            'default' => null, 'help' => 'Straight-line distance from the restaurant to the address. Blank = anywhere in the restaurant\'s city.',
        ],
        'food.delivery_fee_base' => [
            'group' => 'Delivery', 'label' => 'Delivery fee: first kilometres', 'type' => 'decimal', 'unit' => '₹', 'min' => 0, 'max' => 500,
            'default' => null, 'help' => 'Fee for the first kilometres below. Blank = no distance fee: each restaurant\'s own flat delivery fee is used.',
        ],
        'food.delivery_fee_base_km' => [
            'group' => 'Delivery', 'label' => 'Delivery fee: kilometres included', 'type' => 'decimal', 'unit' => 'km', 'min' => 0, 'max' => 20,
            'default' => '2', 'help' => 'Kilometres covered by the first fee.',
        ],
        'food.delivery_fee_per_km' => [
            'group' => 'Delivery', 'label' => 'Delivery fee: each kilometre after that', 'type' => 'decimal', 'unit' => '₹', 'min' => 0, 'max' => 100,
            'default' => '0', 'help' => 'Added for each started kilometre past the included ones. Free delivery above a restaurant\'s amount still applies to online orders only.',
        ],
        // ── tax ──
        'food.gst_percent' => [
            'group' => 'Tax', 'label' => 'GST on food (collected by the platform, section 9(5))', 'type' => 'decimal', 'unit' => '%', 'min' => 0, 'max' => 28,
            'default' => null, 'global' => true, 'help' => 'Restaurant food ordered through an app: the app collects and pays the GST, not the restaurant. CA to confirm the rate (usually 5%). Charged on food + packaging. Blank = no GST charged on food orders.',
        ],
        'food.accept_minutes' => [
            'group' => 'Orders', 'label' => 'Time a restaurant has to accept an order', 'type' => 'int', 'unit' => 'minutes', 'min' => 1, 'max' => 30,
            'default' => '10', 'help' => 'Owner decision 2026-11-02: 10 minutes. Not accepted in time = cancelled and refunded in full.',
        ],
        'food.max_per_dish' => [
            'group' => 'Orders', 'label' => 'Most of one dish in a cart', 'type' => 'int', 'unit' => 'items', 'min' => 1, 'max' => 100,
            'default' => '20', 'help' => 'Dishes have no stock count. A restaurant can set a lower limit on a dish ("Limit per order").',
        ],
        // ── problems & refunds (F4) ──
        'food.issue_window_minutes' => [
            'group' => 'Problems & refunds', 'label' => 'A customer can report a problem for', 'type' => 'int', 'unit' => 'minutes after delivery', 'min' => 15, 'max' => 1440,
            'default' => '120', 'help' => 'Missing, wrong, spilled or poor-quality food. The restaurant is paid only after this time (and never while a problem is open), so its part of a refund can come out of its earnings.',
        ],
        'food.issue_photo_required' => [
            'group' => 'Problems & refunds', 'label' => 'A photo is needed to report a problem', 'type' => 'switch',
            'default' => '1', 'help' => 'On: the customer adds 1 to 3 photos of what arrived.',
        ],
        'food.fault_restaurant_percent' => [
            'group' => 'Problems & refunds', 'label' => "Restaurant's fault: part of the refund the restaurant pays", 'type' => 'int', 'unit' => '%', 'min' => 0, 'max' => 100,
            'default' => '100', 'help' => 'Missing or wrong item, poor quality, bad packing. Taken from the restaurant\'s held earnings for that order (never more than those); the platform pays the rest.',
        ],
        'food.fault_transit_percent' => [
            'group' => 'Problems & refunds', 'label' => 'Damaged on the way: part of the refund the restaurant pays', 'type' => 'int', 'unit' => '%', 'min' => 0, 'max' => 100,
            'default' => '0', 'help' => 'The platform pays the rest. The rider is never charged; the rider gets a strike instead.',
        ],
        'food.fault_unclear_percent' => [
            'group' => 'Problems & refunds', 'label' => 'Cause not clear: part of the refund the restaurant pays', 'type' => 'int', 'unit' => '%', 'min' => 0, 'max' => 100,
            'default' => '0', 'help' => 'The platform pays the rest.',
        ],
        'food.rider_strike_days' => [
            'group' => 'Problems & refunds', 'label' => 'Count a rider\'s strikes over the last', 'type' => 'int', 'unit' => 'days', 'min' => 1, 'max' => 365,
            'default' => '30', 'help' => 'A strike = a food problem found to be damaged on the way. Shown next to the rider\'s name in the problems queue.',
        ],
    ];

    /** Food F5: restaurants can join through /partners. */
    public static function recruitingRestaurants(): bool
    {
        return static::on('food.recruiting_restaurants');
    }

    /** Food F4: the restaurant's part of a refund for this fault, 0–100 (the platform pays the rest). */
    public static function faultRestaurantPercent(string $fault, array $scope = []): int
    {
        $key = match ($fault) {
            'restaurant' => 'food.fault_restaurant_percent',
            'transit' => 'food.fault_transit_percent',
            default => 'food.fault_unclear_percent',
        };

        return max(0, min(100, (int) static::get($key, $scope)));
    }

    public const FOOD_TYPES = ['veg' => 'Veg', 'egg' => 'Egg', 'non_veg' => 'Non-veg'];

    /** The cuisine list from Food Settings, trimmed, no blanks or repeats. */
    public static function cuisines(): array
    {
        return collect(preg_split('/\R/', (string) static::get('food.cuisines')))
            ->map(fn ($c) => trim($c))->filter()->unique()->values()->all();
    }

    /** Food F2: null = not set (rider called at ready / no radius / restaurant flat fee / no GST). */
    public static function decimalOrNull(string $key, array $scope = []): ?float
    {
        $value = static::get($key, $scope);

        return $value === null || $value === '' ? null : (float) $value;
    }

    /** Blank (unset) = packaging charges are off. */
    public static function packagingChargeMax(array $scope = []): ?float
    {
        $value = static::get('food.packaging_charge_max', $scope);

        return $value === null ? null : (float) $value;
    }
}
