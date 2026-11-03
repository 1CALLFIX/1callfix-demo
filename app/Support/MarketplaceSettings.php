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
        'marketplace.hide_test_shops' => [
            'group' => 'Sellers', 'label' => 'Hide test shops from customers', 'type' => 'switch', 'default' => '0', 'global' => true,
            'help' => 'Off (while the shop is being built): the Test Shop from test-accounts shows to everyone, so the whole shop can be tried. Turn ON at go-live: only real shops show; test logins still see test shops. Search engines never list test shops either way.',
        ],
        'marketplace.seller_products_need_approval' => [
            'group' => 'Sellers', 'label' => 'New seller products wait for admin approval', 'type' => 'switch', 'default' => '1',
            'help' => 'On (default): a NEW product a seller adds is hidden from customers until an admin approves it in Admin → Products. Editing a product (name, photos, price, stock) never needs approval again. Off: new products go live at once. The blocked words below always apply.',
        ],
        'marketplace.blocked_product_words' => [
            'group' => 'Sellers', 'label' => 'Blocked words in product names', 'type' => 'text', 'default' => null, 'global' => true,
            'help' => 'One word or phrase per line (for example medicine, alcohol, tobacco). A seller cannot list a product whose name or description contains one. Phase 1 sells no food, medicine or regulated items.',
        ],
        // ── product choices and units (2026-11-02) ──
        'marketplace.product_units' => [
            'group' => 'Products', 'label' => 'Units sellers can pick', 'type' => 'text', 'global' => true,
            'default' => "piece
g
kg
ml
L
m
pair
set
pack
dozen
box
plate",
            'help' => 'One per line. A seller says what one item is, e.g. 500 g, 1 kg, 6 piece; customers see it under the price. Blank = this list.',
        ],
        'marketplace.choice_names' => [
            'group' => 'Products', 'label' => 'Choice types offered to sellers', 'type' => 'text', 'global' => true,
            'default' => "Size
Colour
Weight
Volume
Storage
Pack
Material
Flavour
Portion",
            'help' => 'One per line. Suggested when a seller adds choices to a product (size, colour, portion…). A seller may also type their own. Blank = this list.',
        ],
        // ── commission (E-commerce Phase 4) ──
        'marketplace.commission.franchise_min' => [
            'group' => 'Commission', 'label' => 'Lowest fee a franchise may set', 'type' => 'decimal', 'unit' => '%', 'min' => 0, 'max' => 100,
            'default' => null, 'global' => true, 'help' => 'Franchise admins set shop commission rules for their own city only between these two. Blank = franchise admins cannot set rules.',
        ],
        'marketplace.commission.franchise_max' => [
            'group' => 'Commission', 'label' => 'Highest fee a franchise may set', 'type' => 'decimal', 'unit' => '%', 'min' => 0, 'max' => 100,
            'default' => null, 'global' => true, 'help' => 'Blank = franchise admins cannot set rules.',
        ],
        // ── tax compliance (E-commerce Phase 5; rates from the CA) ──
        'marketplace.tcs_gst_percent' => [
            'group' => 'Tax compliance', 'label' => 'GST TCS withheld from sellers', 'type' => 'decimal', 'unit' => '%', 'min' => 0, 'max' => 5,
            'default' => null, 'global' => true, 'help' => 'Tax collected at source under GST on each seller\'s taxable goods (CA to confirm the rate). Blank = nothing withheld.',
        ],
        'marketplace.tds_194o_percent' => [
            'group' => 'Tax compliance', 'label' => 'Income-tax TDS (194-O) withheld from sellers', 'type' => 'decimal', 'unit' => '%', 'min' => 0, 'max' => 5,
            'default' => null, 'global' => true, 'help' => 'CA to confirm the rate and the threshold rules. Blank = nothing withheld.',
        ],
        'marketplace.grievance_officer' => [
            'group' => 'Tax compliance', 'label' => 'Grievance officer (shown on shop pages)', 'type' => 'text', 'default' => null, 'global' => true,
            'help' => 'Name, designation, phone / email and office address, one per line (E-Commerce Rules 2020). Blank = not shown.',
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
        'marketplace.return_pickup_fee' => [
            'group' => 'Delivery', 'label' => 'Return pickup: rider fee', 'type' => 'decimal', 'unit' => '₹', 'min' => 0,
            'default' => null, 'help' => 'What a rider earns for collecting an accepted return from the customer and taking it back to the shop. The seller pays it from their held earnings; the customer pays nothing (owner decision 2026-10-31). Blank = no rider pickup: the seller or an admin marks the return received by hand.',
        ],
        'marketplace.delivery_promise_minutes' => [
            'group' => 'Delivery', 'label' => 'Delivery promise shown to customers', 'type' => 'int', 'unit' => 'minutes', 'min' => 10, 'max' => 1440,
            'default' => null, 'help' => 'Product page, cart and checkout say "Delivered by about 6:30 PM" (now + these minutes) while the shop is open. Blank = no promise shown.',
        ],
        'marketplace.pickup_promise_minutes' => [
            'group' => 'Delivery', 'label' => 'Pickup promise shown to customers', 'type' => 'int', 'unit' => 'minutes', 'min' => 5, 'max' => 1440,
            'default' => null, 'help' => 'Checkout says "Ready to collect by about 6:00 PM" for pickup orders. Blank = no promise shown.',
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

    /**
     * Shop Phase A 2026-11-02: "Delivered by about 6:30 PM" / "Ready to collect by about 6:00 PM", from the Shop
     * Settings minutes, in the shop's own time zone. Null when the shop is closed or the minutes are not set.
     */
    public static function deliveryPromise(\App\Models\Store $store, string $orderType = 'delivery'): ?string
    {
        if (! $store->is_open) {
            return null;
        }
        $scope = array_filter(['zone_id' => $store->zone_id, 'franchise_id' => $store->franchise_id]);
        $key = $orderType === 'pickup' ? 'marketplace.pickup_promise_minutes' : 'marketplace.delivery_promise_minutes';
        $minutes = (int) self::get($key, $scope);
        if ($minutes <= 0) {
            return null;
        }
        $time = app(\App\Services\TimezoneResolver::class)->format(now()->addMinutes($minutes), $store->franchise, 'g:i A');

        return \App\Support\SiteText::get($orderType === 'pickup' ? 'shop.promise.pickup' : 'shop.promise.delivery', ['time' => $time]);
    }

    /** A one-per-line list setting (units, choice names) as a clean array. */
    public static function lines(string $key): array
    {
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/', (string) self::get($key))), fn ($v) => $v !== '')));
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
