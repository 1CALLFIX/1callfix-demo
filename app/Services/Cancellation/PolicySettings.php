<?php

namespace App\Services\Cancellation;

use App\Models\Booking;
use App\Models\Setting;

/**
 * REF 1CF-CANCEL-POLICY-001 — the single registry of every cancellation-policy setting.
 *
 *  - The admin screen renders and validates from REGISTRY (nothing is hard-coded elsewhere).
 *  - snapshot() freezes the values in force at booking time onto the booking; get() reads that snapshot, so a
 *    later settings change can never alter the charge on an existing booking. A booking created before the
 *    snapshot existed (snapshot = null) falls back to the live setting.
 *  - null  = not configured → the registry default applies (for provider-side rules that default is "not
 *    configured", which keeps the rule inactive rather than inventing a business number);
 *    "0"   = explicitly configured as zero (no fee / no wait).
 *
 * @phpstan-type Meta array{group: string, label: string, type: string, unit: string, default: int|float|string|null, min?: int|float, max?: int|float, options?: array, snapshot: bool, help: string, fallback?: string}
 */
class PolicySettings
{
    public const REGISTRY = [
        // ── charges ──
        'cancellation.assigned_fee' => [
            'group' => 'Charges', 'label' => 'Fee when a professional is assigned but not yet travelling', 'type' => 'decimal', 'unit' => '₹',
            'default' => 0, 'min' => 0, 'snapshot' => true,
            'help' => 'Blank or 0 = no fee. The professional receives nothing for this stage.',
        ],
        'cancellation.en_route_fee' => [
            'group' => 'Charges', 'label' => 'Fee when the professional is on the way', 'type' => 'decimal', 'unit' => '₹',
            'default' => 0, 'min' => 0, 'snapshot' => true,
            'help' => 'Blank or 0 = no fee. The professional receives this fee minus commission.',
        ],
        'cancellation.visit_fee_type' => [
            'group' => 'Charges', 'label' => 'Visit & inspection charge — type', 'type' => 'enum', 'unit' => '',
            'default' => 'flat', 'options' => ['flat' => 'Flat amount', 'percent' => 'Percent of job price'], 'snapshot' => true,
            'help' => 'How the visit charge is calculated.', 'fallback' => 'cancellation.fee_type',
        ],
        'cancellation.visit_fee_value' => [
            'group' => 'Charges', 'label' => 'Visit & inspection charge — value', 'type' => 'decimal', 'unit' => '₹ or %',
            'default' => 0, 'min' => 0, 'snapshot' => true,
            'help' => 'Charged ONLY when the professional has verifiably arrived and NO work is done: the customer refuses, postpones, cannot be reached, or rejects the quote. Never added to a job that is carried out.',
            'fallback' => 'cancellation.fee_value',
        ],
        'cancellation.visit_fee_regular' => [
            'group' => 'Charges', 'label' => 'Visit & inspection charge — regular price (launch display)', 'type' => 'decimal', 'unit' => '₹',
            'default' => null, 'min' => 0, 'snapshot' => true,
            'help' => 'Optional; blank = off. When set higher than the flat visit charge above, customers read "launch price, regular ₹X" next to the charge. Display only: the amount charged is always the visit charge value above. Not used for a percent charge.',
        ],
        'cancellation.visit_fee_launch_wording' => [
            'group' => 'Charges', 'label' => 'Launch-price wording', 'type' => 'text', 'unit' => '',
            'default' => null, 'snapshot' => false,
            'help' => 'Use {charge} and {regular}. Blank = "'.self::DEFAULT_LAUNCH_WORDING.'". Wording only; the amounts always come from the two values above.',
        ],
        // ── spares / interim work ──
        'cancellation.spares_delay_days' => [
            'group' => 'Spare parts & interim work', 'label' => 'Spares delay before the customer may cancel (global)', 'type' => 'int', 'unit' => 'days',
            'default' => 10, 'min' => 1, 'snapshot' => true,
            'help' => 'Cumulative days on hold for spare parts. Per-category overrides below take precedence.',
        ],
        'cancellation.spares_resume_grace_hours' => [
            'group' => 'Spare parts & interim work', 'label' => 'Spares ready — time allowed to resume work', 'type' => 'int', 'unit' => 'hours',
            'default' => 48, 'min' => 1, 'snapshot' => true,
            'help' => 'After this the customer may cancel free and the professional\'s reliability score drops.',
        ],
        'cancellation.interim_cap_percent' => [
            'group' => 'Spare parts & interim work', 'label' => 'Labour cap for the interim-work charge', 'type' => 'percent', 'unit' => '%',
            'default' => 50, 'min' => 0, 'max' => 100, 'snapshot' => true,
            'help' => 'The customer never pays more than this % of the quoted labour for work already done.',
        ],
        'cancellation.interim_min_labour' => [
            'group' => 'Spare parts & interim work', 'label' => 'Minimum labour charge for work already done', 'type' => 'decimal', 'unit' => '₹',
            'default' => null, 'min' => 0, 'snapshot' => true,
            'help' => 'Blank = no minimum. When a customer cancels mid-job after some work was declared, the labour charge is never less than this. It is not a visit or inspection charge and is not affected by the visit charge setting or the Prime visit waiver.',
        ],
        'cancellation.spares_warning_days_before' => [
            'group' => 'Spare parts & interim work', 'label' => 'Spares-delay warning notice — days before the limit', 'type' => 'int', 'unit' => 'days',
            'default' => 3, 'min' => 0, 'snapshot' => false,
            'help' => 'With a 10-day limit, 3 sends the first notice on day 7; the unlock notice goes out at the limit (day 10). 0 = no warning notice.',
        ],
        'booking.extra_work_timeout_hours' => [
            'group' => 'Spare parts & interim work', 'label' => 'Customer response timeout for extra work', 'type' => 'int', 'unit' => 'hours',
            'default' => 72, 'min' => 1, 'snapshot' => false,
            'help' => 'Unanswered extra work is auto-declined after this and the job resumes at the original price.',
        ],
        'cancellation.dispute_window_hours' => [
            'group' => 'Spare parts & interim work', 'label' => 'Time the customer has to dispute declared progress', 'type' => 'int', 'unit' => 'hours',
            'default' => 48, 'min' => 1, 'snapshot' => false,
            'help' => 'After the professional declares work done on a spares hold, the customer can dispute it for this long.',
        ],
        // ── professional-side rules ──
        'cancellation.arrival_radius_meters' => [
            'group' => 'Arrival, no-show & quotes', 'label' => 'Arrival check-in radius', 'type' => 'int', 'unit' => 'metres',
            'default' => null, 'min' => 1, 'snapshot' => true,
            'help' => 'The professional can mark "Arrived" only within this distance of the booking address. Blank = arrival cannot be verified, so no visit charge can ever be levied.',
        ],
        'cancellation.no_show_wait_minutes' => [
            'group' => 'Arrival, no-show & quotes', 'label' => 'Customer not home — minutes to wait after arrival', 'type' => 'int', 'unit' => 'minutes',
            'default' => null, 'min' => 0, 'snapshot' => true,
            'help' => 'Blank = the professional cannot cancel with the visit charge for an unreachable customer.',
        ],
        'cancellation.no_show_call_attempts' => [
            'group' => 'Arrival, no-show & quotes', 'label' => 'Customer not home — call attempts required', 'type' => 'int', 'unit' => 'attempts',
            'default' => null, 'min' => 1, 'snapshot' => true,
            'help' => 'In-app call attempts that must be logged first. Blank = no-show cancel with charge is unavailable.',
        ],
        'cancellation.quote_response_minutes' => [
            'group' => 'Arrival, no-show & quotes', 'label' => 'Quote unanswered counts as "not accepted" after', 'type' => 'int', 'unit' => 'minutes',
            'default' => null, 'min' => 1, 'snapshot' => true,
            'help' => 'Blank = only an explicit customer rejection allows the quote-rejected cancel.',
        ],
        'cancellation.provider_late_minutes' => [
            'group' => 'Arrival, no-show & quotes', 'label' => 'Professional late beyond', 'type' => 'int', 'unit' => 'minutes',
            'default' => null, 'min' => 1, 'snapshot' => true,
            'help' => 'Past this the customer cancels free and the professional\'s reliability score drops. Blank = rule inactive.',
        ],
        'cancellation.reliability_penalty_points' => [
            'group' => 'Arrival, no-show & quotes', 'label' => 'Reliability points deducted per penalty', 'type' => 'int', 'unit' => 'points',
            'default' => 10, 'min' => 0, 'max' => 100, 'snapshot' => false,
            'help' => 'Applied for provider-late, provider-cancelled and spares-not-resumed events.',
        ],
        // ── collection ──
        'cancellation.unpaid_flag_days' => [
            'group' => 'Collection', 'label' => 'Unpaid cancellation charge is flagged after', 'type' => 'int', 'unit' => 'days',
            'default' => 7, 'min' => 1, 'snapshot' => false,
            'help' => 'After this the charge goes to the admin queue to chase or waive with a reason.',
        ],
        // ── legacy admin-cancel fee (not used by the customer policy above) ──
        'cancellation.free_minutes' => [
            'group' => 'Admin-initiated cancel (legacy time-based fee)', 'label' => 'Free window', 'type' => 'int', 'unit' => 'minutes',
            'default' => 15, 'min' => 0, 'snapshot' => false,
            'help' => 'Used only when an operator cancels from the admin panel without waiving. Customer cancellation uses the charges above.',
        ],
        'cancellation.fee_type' => [
            'group' => 'Admin-initiated cancel (legacy time-based fee)', 'label' => 'Fee type', 'type' => 'enum', 'unit' => '',
            'default' => 'flat', 'options' => ['flat' => 'Flat amount', 'percent' => 'Percent of job price'], 'snapshot' => false,
            'help' => 'Also the fallback for the visit charge type while that is unset.',
        ],
        'cancellation.fee_value' => [
            'group' => 'Admin-initiated cancel (legacy time-based fee)', 'label' => 'Fee value', 'type' => 'decimal', 'unit' => '₹ or %',
            'default' => 0, 'min' => 0, 'snapshot' => false,
            'help' => 'Also the fallback for the visit charge value while that is unset.',
        ],
    ];

    public const CATEGORY_OVERRIDE_PREFIX = 'cancellation.spares_delay_days.category_';

    public const DEFAULT_LAUNCH_WORDING = 'Visit charge {charge} (launch price, regular {regular})';

    /** Settings scope hints for a booking (same Global→Country→City→Zone→Module→Franchise cascade as every setting). */
    public static function scopeFor(Booking $booking): array
    {
        $booking->loadMissing('franchise');

        return array_filter([
            'zone_id' => $booking->zone_id,
            'franchise_id' => $booking->franchise_id,
            'city_id' => $booking->franchise?->city_id,
            'country_id' => $booking->franchise?->country_id,
        ]);
    }

    /** The values in force right now (raw: null = unset), keyed by setting key — frozen onto the booking at creation. */
    public static function snapshot(Booking $booking): array
    {
        $scope = self::scopeFor($booking);
        $snap = [];

        foreach (self::REGISTRY as $key => $meta) {
            if ($meta['snapshot']) {
                $snap[$key] = self::live($key, $scope);
            }
        }

        $booking->loadMissing('service');
        if ($categoryId = $booking->service?->category_id) {
            $snap[self::CATEGORY_OVERRIDE_PREFIX.$categoryId] = Setting::get(self::CATEGORY_OVERRIDE_PREFIX.$categoryId, null, $scope);
        }

        return $snap;
    }

    /** The effective value for a booking: its snapshot first, the live setting only for a booking that has none; null → registry default. */
    public static function get(Booking $booking, string $key): int|float|string|null
    {
        $snap = $booking->cancellation_policy_snapshot;
        $raw = (is_array($snap) && array_key_exists($key, $snap)) ? $snap[$key] : self::live($key, self::scopeFor($booking));

        return self::cast($key, $raw);
    }

    /** Effective value from the live settings (for screens with no booking: the policy text preview, the admin form). */
    public static function current(string $key, array $scope = []): int|float|string|null
    {
        return self::cast($key, self::live($key, $scope));
    }

    private static function live(string $key, array $scope): ?string
    {
        $raw = Setting::get($key, null, $scope);
        if (($raw === null || $raw === '') && ($fallback = self::REGISTRY[$key]['fallback'] ?? null)) {
            $raw = Setting::get($fallback, null, $scope);
        }

        return ($raw === null || $raw === '') ? null : (string) $raw;
    }

    /** Cast a raw stored/typed value ('' / null → the registry default). */
    public static function cast(string $key, mixed $raw): int|float|string|null
    {
        $meta = self::REGISTRY[$key] ?? null;
        $value = ($raw === null || $raw === '') ? ($meta['default'] ?? null) : $raw;

        if ($value === null) {
            return null;
        }

        return match ($meta['type'] ?? 'int') {
            'decimal' => round((float) $value, 2),
            'enum', 'text' => (string) $value,
            default => (int) $value,
        };
    }

    /**
     * Validate one value coming from the admin screen. null/'' = clear the key (back to its default).
     *
     * @return string|null an error message, or null when valid
     */
    public static function validate(string $key, mixed $value, ?string $visitFeeType = null): ?string
    {
        $meta = self::REGISTRY[$key] ?? null;
        if (! $meta) {
            return "Unknown setting {$key}.";
        }

        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $value = trim((string) $value);

        if ($meta['type'] === 'text') {
            if (mb_strlen($value) > 200) {
                return 'At most 200 characters.';
            }
            if ($key === 'cancellation.visit_fee_launch_wording' && (! str_contains($value, '{charge}') || ! str_contains($value, '{regular}'))) {
                return 'The wording must contain both {charge} and {regular}.';
            }

            return null;
        }

        if ($meta['type'] === 'enum') {
            return array_key_exists($value, $meta['options']) ? null : 'Choose one of: '.implode(', ', array_keys($meta['options'])).'.';
        }

        if (! is_numeric($value)) {
            return 'Enter a number.';
        }

        if ($meta['type'] === 'int' && (float) $value !== floor((float) $value)) {
            return 'Enter a whole number.';
        }

        $number = (float) $value;
        if ($number < ($meta['min'] ?? 0)) {
            return 'Must be '.(($meta['min'] ?? 0) > 0 ? 'at least '.$meta['min'] : 'zero or more').' — no negatives.';
        }
        if (isset($meta['max']) && $number > $meta['max']) {
            return "Must be at most {$meta['max']}.";
        }
        if ($key === 'cancellation.visit_fee_regular' && $visitFeeType === 'percent') {
            return 'A regular price only applies to a flat visit charge, not a percent.';
        }
        if (in_array($key, ['cancellation.visit_fee_value', 'cancellation.fee_value'], true) && $visitFeeType === 'percent' && $number > 100) {
            return 'A percent must be between 0 and 100.';
        }

        return null;
    }

    public static function isCategoryOverrideKey(string $key): bool
    {
        return str_starts_with($key, self::CATEGORY_OVERRIDE_PREFIX) && ctype_digit(substr($key, strlen(self::CATEGORY_OVERRIDE_PREFIX)));
    }
}
