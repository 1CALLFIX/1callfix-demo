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
        'food.accept_minutes' => [
            'group' => 'Orders', 'label' => 'Time a restaurant has to accept an order', 'type' => 'int', 'unit' => 'minutes', 'min' => 1, 'max' => 30,
            'default' => '10', 'help' => 'Owner decision 2026-11-02: 10 minutes. Not accepted in time = cancelled and refunded in full.',
        ],
    ];

    public const FOOD_TYPES = ['veg' => 'Veg', 'egg' => 'Egg', 'non_veg' => 'Non-veg'];

    /** The cuisine list from Food Settings, trimmed, no blanks or repeats. */
    public static function cuisines(): array
    {
        return collect(preg_split('/\R/', (string) static::get('food.cuisines')))
            ->map(fn ($c) => trim($c))->filter()->unique()->values()->all();
    }

    /** Blank (unset) = packaging charges are off. */
    public static function packagingChargeMax(array $scope = []): ?float
    {
        $value = static::get('food.packaging_charge_max', $scope);

        return $value === null ? null : (float) $value;
    }
}
