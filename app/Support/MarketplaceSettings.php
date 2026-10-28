<?php

namespace App\Support;

use App\Models\Setting;

/**
 * E-commerce Phase 1 — every shop (Commerce) value Super Admin sets on Admin → Shop Settings, read through the one
 * settings cascade (Setting::get(), zone → franchise → city → country → global). Before this screen the Phase 0
 * values were set with tinker. The rules themselves (online-only benefits, approval model) are not here; only
 * their values are.
 *
 * Types: 'switch' is ON only when the stored value is exactly '1'; 'int' / 'decimal' read the registry default when
 * unset; 'choice' is one of `options`.
 */
final class MarketplaceSettings
{
    public const REGISTRY = [
        // ── sellers ──
        'marketplace.recruiting_sellers' => [
            'group' => 'Sellers', 'label' => 'Take seller sign-ups on /partners', 'type' => 'switch', 'default' => '0', 'global' => true,
            'help' => 'On: "Seller or shop" on the partner page continues to the sign-up (OTP, documents, admin approval). Off: the waiting list. Turn on before Commerce opens so shops are approved and stocked first.',
        ],
        'marketplace.seller_products_need_approval' => [
            'group' => 'Sellers', 'label' => 'New seller products wait for admin approval', 'type' => 'switch', 'default' => '1',
            'help' => 'On (default): a product a seller adds is hidden from customers until an admin approves it in Admin → Products. Off: it goes live at once (the blocked words below still apply).',
        ],
        'marketplace.blocked_product_words' => [
            'group' => 'Sellers', 'label' => 'Blocked words in product names', 'type' => 'text', 'default' => null, 'global' => true,
            'help' => 'One word or phrase per line (for example medicine, alcohol, tobacco). A seller cannot list a product whose name or description contains one. Phase 1 sells no food, medicine or regulated items.',
        ],
        // ── orders ──
        'marketplace.unpaid_expiry_minutes' => [
            'group' => 'Orders', 'label' => 'Cancel unpaid online orders after', 'type' => 'int', 'unit' => 'minutes', 'min' => 5, 'max' => 1440,
            'default' => '30', 'help' => 'An online order not paid in this time is cancelled and its stock put back.',
        ],
        'marketplace.return_window_days' => [
            'group' => 'Orders', 'label' => 'Return window', 'type' => 'int', 'unit' => 'days', 'min' => 0, 'max' => 30,
            'default' => '3', 'help' => 'Owner decision: 3 days, damaged or wrong item only. The seller is paid after this window.',
        ],
        // ── delivery ──
        'marketplace.rider_min_payout' => [
            'group' => 'Delivery', 'label' => 'Minimum rider payout per delivery', 'type' => 'decimal', 'unit' => '₹', 'min' => 0,
            'default' => null, 'help' => 'The rider gets at least this on every delivery, including free-delivery orders. Blank = no minimum (a rider earns nothing on a free-delivery order). Set before Commerce goes ON.',
        ],
        'marketplace.rider_min_payout_funded_by' => [
            'group' => 'Delivery', 'label' => 'Minimum payout top-up paid by', 'type' => 'choice', 'options' => ['platform' => 'Platform commission', 'seller' => 'Seller share'],
            'default' => 'platform', 'help' => 'Who pays the gap when the rider\'s share of the delivery fee is below the minimum.',
        ],
        'marketplace.dispatch.offer_batch_size' => [
            'group' => 'Delivery', 'label' => 'Riders offered per round', 'type' => 'int', 'unit' => 'riders', 'min' => 1, 'max' => 50,
            'default' => '5', 'help' => 'How many nearby riders see a ready order at once.',
        ],
        'marketplace.dispatch.offer_timeout_seconds' => [
            'group' => 'Delivery', 'label' => 'Seconds a rider has to accept', 'type' => 'int', 'unit' => 'seconds', 'min' => 10, 'max' => 600,
            'default' => '25', 'help' => 'After this the next round of riders is offered.',
        ],
        'marketplace.dispatch.max_rounds' => [
            'group' => 'Delivery', 'label' => 'Offer rounds before admin assigns', 'type' => 'int', 'unit' => 'rounds', 'min' => 1, 'max' => 50,
            'default' => '6', 'help' => 'After the last round the order waits for an admin to assign a rider.',
        ],
    ];

    public static function field(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    /** Effective value in this scope; unset → the registry default. */
    public static function get(string $key, array $scope = []): ?string
    {
        $raw = Setting::get($key, null, $scope);

        return ($raw === null || $raw === '') ? self::REGISTRY[$key]['default'] : (string) $raw;
    }

    public static function on(string $key, array $scope = []): bool
    {
        return self::get($key, $scope) === '1';
    }

    public static function recruitingSellers(): bool
    {
        return self::on('marketplace.recruiting_sellers');
    }

    /** The first blocked word (Shop Settings) found in this text, or null. Case-insensitive. */
    public static function blockedWordIn(string $text): ?string
    {
        $haystack = mb_strtolower($text);
        foreach (preg_split('/\R/', (string) self::get('marketplace.blocked_product_words')) as $word) {
            $word = mb_strtolower(trim($word));
            if ($word !== '' && str_contains($haystack, $word)) {
                return $word;
            }
        }

        return null;
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

        if ($meta['type'] === 'text') {
            return mb_strlen($value) <= 5000 ? null : 'Keep it under 5000 characters.';
        }
        if ($meta['type'] === 'switch') {
            return in_array($value, ['0', '1'], true) ? null : 'Choose on or off.';
        }
        if ($meta['type'] === 'choice') {
            return array_key_exists($value, $meta['options']) ? null : 'Choose one of the options.';
        }
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
