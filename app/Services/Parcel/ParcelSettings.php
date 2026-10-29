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
        // ── scheduled delivery (Stage 1, 2026-10-25) ──
        'parcel.schedule_base_fare' => [
            'group' => 'Scheduled delivery', 'label' => 'Base fare — scheduled parcel', 'type' => 'decimal', 'unit' => '₹', 'required' => false, 'min' => 0,
            'default' => null, 'help' => 'Replaces the base fare when the customer picks a pickup time instead of "Now". Distance, weight and size are charged the same. It is its own price, not a discount, so any payment method can be used. Blank = customers cannot schedule.',
        ],
        'parcel.schedule_min_lead_minutes' => [
            'group' => 'Scheduled delivery', 'label' => 'Earliest pickup time from now', 'type' => 'int', 'unit' => 'minutes', 'required' => false, 'min' => 15, 'max' => 1440,
            'default' => 60, 'help' => 'A scheduled pickup must be at least this far ahead. Blank = 60.',
        ],
        'parcel.schedule_max_days_ahead' => [
            'group' => 'Scheduled delivery', 'label' => 'Latest pickup day', 'type' => 'int', 'unit' => 'days', 'required' => false, 'min' => 1, 'max' => 30,
            'default' => 7, 'help' => 'How many days ahead a customer can schedule. Blank = 7.',
        ],
        'parcel.schedule_dispatch_lead_minutes' => [
            'group' => 'Scheduled delivery', 'label' => 'Offer to riders before the pickup time', 'type' => 'int', 'unit' => 'minutes', 'required' => false, 'min' => 5, 'max' => 240,
            'default' => 30, 'help' => 'A scheduled parcel is offered to riders this long before its pickup time. Blank = 30.',
        ],
        // ── waiting charge at pickup (Stage 1, 2026-10-25) ──
        'parcel.waiting_free_minutes' => [
            'group' => 'Waiting charge', 'label' => 'Free waiting time at pickup', 'type' => 'int', 'unit' => 'minutes', 'required' => false, 'min' => 0, 'max' => 120,
            'default' => null, 'help' => 'Counted from when the rider taps "I\'ve arrived" at the pickup point. Blank = no waiting charge.',
        ],
        'parcel.waiting_block_minutes' => [
            'group' => 'Waiting charge', 'label' => 'Charge for every', 'type' => 'int', 'unit' => 'minutes', 'required' => false, 'min' => 1, 'max' => 60,
            'default' => 15, 'help' => 'Waiting beyond the free time is charged per started block of this many minutes. Blank = 15.',
        ],
        'parcel.waiting_charge_per_block' => [
            'group' => 'Waiting charge', 'label' => 'Charge per block', 'type' => 'decimal', 'unit' => '₹', 'required' => false, 'min' => 0,
            'default' => null, 'help' => 'Paid by the sender to the rider at pickup (cash or UPI), on top of the delivery price. Blank = no waiting charge.',
        ],
        'parcel.arrival_radius_km' => [
            'group' => 'Waiting charge', 'label' => 'Rider must be this close to tap "I\'ve arrived"', 'type' => 'decimal', 'unit' => 'km', 'required' => false, 'min' => 0.05, 'max' => 5,
            'default' => 0.3, 'help' => 'Checked against the rider\'s live location, so waiting time cannot start early. Blank = 0.3.',
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
        // ── pickup safety (2026-10-29: drugs and banned items) ──
        'parcel.refusal_fee' => [
            'group' => 'Pickup safety', 'label' => 'Refusal fee kept from the sender', 'type' => 'decimal', 'unit' => '₹', 'required' => false, 'min' => 0,
            'default' => null, 'help' => 'The rider refused the parcel because it holds a banned item, or the sender would not let the rider check it: this much is kept from the sender\'s online or wallet refund. Not charged when the rider refuses for another reason, and not collected on a cash order. Blank = no fee.',
        ],
        'parcel.refusal_rider_pay' => [
            'group' => 'Pickup safety', 'label' => 'Paid to the rider for a refused parcel', 'type' => 'decimal', 'unit' => '₹', 'required' => false, 'min' => 0,
            'default' => null, 'help' => 'Credited to the rider\'s wallet for the wasted trip whenever they refuse a parcel at the pickup point (the app checks they are there). Blank = nothing.',
        ],
    ];

    public const SIZES = ['small' => 'Small', 'medium' => 'Medium', 'large' => 'Large'];

    /** What is inside (Stage 1) — shown to the rider so they know how to carry it. */
    public const CONTENTS = [
        'documents' => 'Documents', 'food' => 'Food', 'clothes' => 'Clothes', 'electronics' => 'Electronics',
        'medicines' => 'Medicines', 'groceries' => 'Groceries', 'gift' => 'Gift', 'other' => 'Other',
    ];

    /**
     * The sender confirms the parcel holds none of the banned items before booking (every channel). The list is
     * edited on Parcel Settings (`parcel.restricted_items`, one item per line, per scope); this is what applies
     * while it has never been saved, so the declaration can never be empty.
     */
    public const RESTRICTED_ITEMS_KEY = 'parcel.restricted_items';

    public const DEFAULT_RESTRICTED_ITEMS = [
        'Cash, gold, jewellery or other valuables',
        'Alcohol, tobacco or drugs',
        'Weapons, explosives, fireworks or ammunition',
        'Gas cylinders, petrol or anything that can catch fire',
        'Live animals',
        'Anything illegal or stolen',
    ];

    public const RESTRICTED_ITEMS_MAX = 20;

    /** Pickup safety: what looked unusual when the rider took a photo (owner, 2026-10-29). */
    public const SAFETY_CONCERNS = [
        'sender' => 'The sender', 'location' => 'The pickup place', 'item' => 'The item',
        'packing' => 'The packing', 'time' => 'The pickup time', 'other' => 'Something else',
    ];

    /** The sender lets the rider photograph the item before packing and the parcel after packing. */
    public const SAFETY_PHOTO_STAGES = [
        'before_packing' => 'Item, before packing', 'after_packing' => 'Parcel, after packing', 'other' => 'Something else',
    ];

    /** Photos per order, so a stuck camera button cannot fill the disk. */
    public const SAFETY_PHOTOS_MAX = 10;

    /**
     * Why the rider refused the parcel at pickup. `fee` = parcel.refusal_fee may be kept from the sender's refund
     * (the sender broke the rules); `customer` is what the sender is told.
     */
    public const REFUSAL_REASONS = [
        'restricted_item' => ['label' => 'It holds a banned item', 'fee' => true,
            'customer' => 'it looks like it holds an item we cannot carry'],
        'refused_check' => ['label' => 'The sender would not let me check or photograph it', 'fee' => true,
            'customer' => 'the rider was not allowed to check the item'],
        'suspicious' => ['label' => 'Something else is not right (sender, place, time or packing)', 'fee' => false,
            'customer' => 'the rider could not safely accept it'],
    ];

    /** @return list<string> the banned items list in force in this scope */
    public static function restrictedItems(array $scope = []): array
    {
        $items = self::parseRestrictedItems(Setting::get(self::RESTRICTED_ITEMS_KEY, null, $scope));

        return $items === [] ? self::DEFAULT_RESTRICTED_ITEMS : $items;
    }

    /** @return list<string> one trimmed, non-empty item per line, duplicates dropped */
    public static function parseRestrictedItems(?string $text): array
    {
        $lines = preg_split('/\R/', (string) $text) ?: [];

        return array_values(array_unique(array_filter(array_map(fn ($line) => trim(preg_replace('/^[-*•\s]+/u', '', $line)), $lines), fn ($line) => $line !== '')));
    }

    /** @return string|null an error message, or null when valid. Blank = the standard list. */
    public static function validateRestrictedItems(?string $text): ?string
    {
        $items = self::parseRestrictedItems($text);
        if (count($items) > self::RESTRICTED_ITEMS_MAX) {
            return 'At most '.self::RESTRICTED_ITEMS_MAX.' items.';
        }
        foreach ($items as $item) {
            if (mb_strlen($item) > 150) {
                return 'Keep each item under 150 characters.';
            }
        }

        return null;
    }

    /** Two-wheeler only for now (Stage 1); 3- and 4-wheelers come with their own rider pool later. */
    public const VEHICLES = ['two_wheeler' => 'Two-wheeler'];

    public const DELIVERY_TYPES = ['now' => 'Now', 'scheduled' => 'Schedule'];

    /** Scheduled delivery is offered only where its own base fare is set. */
    public static function scheduleAvailable(array $scope = []): bool
    {
        return self::missingRequired($scope) === [] && self::get('parcel.schedule_base_fare', $scope) !== null;
    }

    /** The waiting charge applies only when both the free time and the rate are set. */
    public static function waitingChargeEnabled(array $scope = []): bool
    {
        return self::get('parcel.waiting_free_minutes', $scope) !== null && self::get('parcel.waiting_charge_per_block', $scope) !== null;
    }

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
