<?php

namespace App\Services\Parcel;

use App\Models\Setting;

/**
 * Every admin-controlled Parcel value in one registry: pricing (owner decision 2026-10-23: distance + weight +
 * size), operations and dispatch. Backs the admin Parcel Settings screen (ParcelOrders\Settings) and every
 * reader, so the screen and the code can never disagree about a key, its unit or its default.
 *
 * Rule of Law: a `required` pricing key that is unset means parcel cannot be quoted or booked in that scope —
 * never a hidden in-code price. Optional keys say in their help what blank means.
 */
class ParcelSettings
{
    public const REGISTRY = [
        // ── pricing ──
        'parcel.base_fare' => [
            'group' => 'Pricing', 'label' => 'Base fare', 'type' => 'decimal', 'unit' => '₹', 'required' => true, 'min' => 0,
            'default' => null, 'help' => 'Charged on every parcel; covers the first "included distance" below. Blank = parcel cannot be booked.',
        ],
        'parcel.base_km' => [
            'group' => 'Pricing', 'label' => 'Distance included in the base fare', 'type' => 'decimal', 'unit' => 'km', 'required' => true, 'min' => 0,
            'default' => null, 'help' => 'Distance covered by the base fare. 0 = every km is charged at the per-km rate. Blank = parcel cannot be booked.',
        ],
        'parcel.per_km_rate' => [
            'group' => 'Pricing', 'label' => 'Rate per km after the included distance', 'type' => 'decimal', 'unit' => '₹/km', 'required' => true, 'min' => 0,
            'default' => null, 'help' => 'Blank = parcel cannot be booked.',
        ],
        'parcel.road_distance_factor' => [
            'group' => 'Pricing', 'label' => 'Road distance factor', 'type' => 'decimal', 'unit' => '×', 'required' => false, 'min' => 1, 'max' => 3,
            'default' => 1, 'help' => 'Distance is measured in a straight line between the two map pins and multiplied by this to approximate road distance (e.g. 1.3). Blank = 1 (straight line).',
        ],
        'parcel.free_weight_kg' => [
            'group' => 'Pricing', 'label' => 'Weight included free', 'type' => 'decimal', 'unit' => 'kg', 'required' => false, 'min' => 0,
            'default' => 0, 'help' => 'Weight above this is charged at the per-kg rate. Blank = 0.',
        ],
        'parcel.per_kg_rate' => [
            'group' => 'Pricing', 'label' => 'Rate per kg above the free weight', 'type' => 'decimal', 'unit' => '₹/kg', 'required' => false, 'min' => 0,
            'default' => 0, 'help' => 'Blank = 0 (weight is not charged).',
        ],
        'parcel.size_surcharge_small' => [
            'group' => 'Pricing', 'label' => 'Surcharge — small package', 'type' => 'decimal', 'unit' => '₹', 'required' => false, 'min' => 0,
            'default' => 0, 'help' => 'Fits in a hand / envelope. Blank = 0.',
        ],
        'parcel.size_surcharge_medium' => [
            'group' => 'Pricing', 'label' => 'Surcharge — medium package', 'type' => 'decimal', 'unit' => '₹', 'required' => false, 'min' => 0,
            'default' => 0, 'help' => 'Shoebox to backpack size. Blank = 0.',
        ],
        'parcel.size_surcharge_large' => [
            'group' => 'Pricing', 'label' => 'Surcharge — large package', 'type' => 'decimal', 'unit' => '₹', 'required' => false, 'min' => 0,
            'default' => 0, 'help' => 'Needs both hands, still fits on a two-wheeler. Blank = 0.',
        ],
        // ── limits ──
        'parcel.max_distance_km' => [
            'group' => 'Limits', 'label' => 'Longest delivery accepted', 'type' => 'decimal', 'unit' => 'km', 'required' => true, 'min' => 0.1,
            'default' => null, 'help' => 'Measured the same way as the price (after the road factor). Blank = parcel cannot be booked.',
        ],
        'parcel.max_weight_kg' => [
            'group' => 'Limits', 'label' => 'Heaviest parcel accepted', 'type' => 'decimal', 'unit' => 'kg', 'required' => true, 'min' => 0.1,
            'default' => null, 'help' => 'Blank = parcel cannot be booked.',
        ],
        // ── operations ──
        'parcel.unpaid_expiry_minutes' => [
            'group' => 'Operations', 'label' => 'Cancel an unpaid online order after', 'type' => 'int', 'unit' => 'minutes', 'required' => false, 'min' => 5, 'max' => 1440,
            'default' => 30, 'help' => 'An online order is not sent to riders until it is paid. Unpaid after this long, it is cancelled automatically (no charge). Blank = 30.',
        ],
        'parcel.rider_cash_due_limit' => [
            'group' => 'Operations', 'label' => 'Rider cash dues limit', 'type' => 'decimal', 'unit' => '₹', 'required' => false, 'min' => 0,
            'default' => null, 'help' => 'A rider who collected cash owes the platform and franchise share. Once their unpaid dues reach this amount they get no more CASH orders until they settle. Blank = no limit.',
        ],
        // ── dispatch ──
        'parcel.dispatch.offer_batch_size' => [
            'group' => 'Rider dispatch', 'label' => 'Riders offered per round', 'type' => 'int', 'unit' => 'riders', 'required' => false, 'min' => 1, 'max' => 20,
            'default' => 5, 'help' => 'Nearest online riders offered the order at once. Blank = 5.',
        ],
        'parcel.dispatch.offer_timeout_seconds' => [
            'group' => 'Rider dispatch', 'label' => 'Time a rider has to accept', 'type' => 'int', 'unit' => 'seconds', 'required' => false, 'min' => 10, 'max' => 300,
            'default' => 25, 'help' => 'Blank = 25.',
        ],
        'parcel.dispatch.max_rounds' => [
            'group' => 'Rider dispatch', 'label' => 'Search rounds before handing to admin', 'type' => 'int', 'unit' => 'rounds', 'required' => false, 'min' => 1, 'max' => 120,
            'default' => 6, 'help' => 'After this many rounds with no acceptance the order waits for an admin to assign a rider. Blank = 6.',
        ],
    ];

    public const SIZES = ['small' => 'Small', 'medium' => 'Medium', 'large' => 'Large'];

    public static function field(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    /** Effective value in this scope; unset → the registry default (null for a required key). */
    public static function get(string $key, array $scope = []): int|float|null
    {
        $meta = self::REGISTRY[$key];
        $raw = Setting::get($key, null, $scope);
        $value = ($raw === null || $raw === '') ? $meta['default'] : $raw;

        if ($value === null) {
            return null;
        }

        return $meta['type'] === 'int' ? (int) $value : round((float) $value, 2);
    }

    /** Required pricing keys that are unset in this scope (empty = parcel can be quoted). */
    public static function missingRequired(array $scope = []): array
    {
        return array_values(array_filter(
            array_keys(self::REGISTRY),
            fn ($key) => (self::REGISTRY[$key]['required'] ?? false) && self::get($key, $scope) === null
        ));
    }

    /** @return string|null an error message, or null when valid. null/'' clears the key. */
    public static function validate(string $key, mixed $value): ?string
    {
        $meta = self::REGISTRY[$key] ?? null;
        if (! $meta) {
            return "Unknown setting {$key}.";
        }
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $value = trim((string) $value);
        if (! is_numeric($value)) {
            return 'Enter a number.';
        }
        if ($meta['type'] === 'int' && (float) $value !== floor((float) $value)) {
            return 'Enter a whole number.';
        }

        $number = (float) $value;
        if ($number < ($meta['min'] ?? 0)) {
            return 'Must be at least '.($meta['min'] ?? 0).'.';
        }
        if (isset($meta['max']) && $number > $meta['max']) {
            return "Must be at most {$meta['max']}.";
        }

        return null;
    }
}
