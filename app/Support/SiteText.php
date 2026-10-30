<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Owner rule 2026-10-31: every word a customer reads is controlled by the Super Admin, in English and Telugu.
 * Admin → Growth → Website text & language edits these; nothing here changes the live site until it is saved.
 *
 * Each entry: key => [group, what it is (admin label), English default, Telugu default]. A saved value lives in the
 * settings store as `text.{key}.en` / `text.{key}.te`; blank or unset reads as the default. In Telugu a missing
 * Telugu value falls back to the Telugu default, then to English, so a page is never blank.
 *
 * Text that already had its own Super Admin field before this screen (Admin → Homepage & sign-up) keeps that field as
 * its English value: LEGACY maps the old setting key to the entry here, so only the Telugu side is new.
 *
 * Telugu is OFF until the Super Admin switches it on (i18n.telugu_enabled), so the Telugu defaults can be checked by
 * a native reader first. Customers then choose with the switch in the top bar (cookie, per device). Admin, provider,
 * rider and seller panels stay English.
 */
final class SiteText
{
    public const LOCALES = ['en', 'te'];
    public const COOKIE = 'cf_locale';

    public const TELUGU_ENABLED = 'i18n.telugu_enabled';
    public const DEFAULT_LOCALE = 'i18n.default_locale';

    public const GROUPS = [
        'language' => 'Language switch',
        'header' => 'Top bar & menu',
        'nav' => 'Bottom menu (phones)',
        'home' => 'Homepage',
        'cart' => 'Cart',
        'shop' => 'Shop',
    ];

    /** @var array<string, array{0: string, 1: string, 2: string, 3: string}> */
    public const TEXTS = [
        'language.to_te' => ['language', 'Switch button, shown while in English', 'తెలుగు', 'తెలుగు'],
        'language.to_en' => ['language', 'Switch button, shown while in Telugu', 'English', 'English'],

        'header.search_placeholder' => ['header', 'Search box hint', 'Search services, products, shops…', 'సేవలు, వస్తువులు, షాపులు వెతకండి…'],
        'header.sign_in' => ['header', 'Sign in link', 'Sign in', 'సైన్ ఇన్'],
        'header.book_now' => ['header', 'Blue button', 'Book Now', 'బుక్ చేయండి'],
        'header.partner' => ['header', '"Become a partner" button (English is set on Homepage & sign-up)', 'Become a partner', 'భాగస్వామిగా చేరండి'],
        'header.menu.services' => ['header', 'Menu: services', 'Services', 'సేవలు'],
        'header.menu.categories' => ['header', 'Menu: categories', 'Categories', 'విభాగాలు'],
        'header.menu.shop' => ['header', 'Menu: shop', 'Shop', 'షాప్'],
        'header.menu.bookings' => ['header', 'Menu: bookings', 'My bookings', 'నా బుకింగ్‌లు'],
        'header.menu.parcels' => ['header', 'Menu: parcels', 'My parcels', 'నా పార్సిల్స్'],
        'header.menu.shop_orders' => ['header', 'Menu: shop orders', 'Shop orders', 'షాప్ ఆర్డర్లు'],
        'header.menu.my_shop' => ['header', 'Menu: seller\'s own shop', 'My shop', 'నా షాప్'],
        'header.menu.rider' => ['header', 'Menu: rider dashboard', 'Rider dashboard', 'రైడర్ డాష్‌బోర్డ్'],
        'header.menu.earnings' => ['header', 'Menu: earnings', 'Earnings', 'సంపాదన'],
        'header.menu.partner_dashboard' => ['header', 'Menu: partner dashboard', 'Partner dashboard', 'భాగస్వామి డాష్‌బోర్డ్'],
        'header.menu.account' => ['header', 'Menu: account', 'My account', 'నా ఖాతా'],
        'header.menu.help' => ['header', 'Menu: help', 'Help Center', 'సహాయ కేంద్రం'],
        'header.menu.logout' => ['header', 'Menu: log out', 'Log out', 'లాగ్ అవుట్'],
        'header.cart' => ['header', 'Cart icon (screen readers)', 'Cart', 'కార్ట్'],

        'nav.home' => ['nav', 'Home', 'Home', 'హోమ్'],
        'nav.services' => ['nav', 'Services', 'Services', 'సేవలు'],
        'nav.shop' => ['nav', 'Shop (only where the shop is on)', 'Shop', 'షాప్'],
        'nav.search' => ['nav', 'Search (where the shop is off)', 'Search', 'వెతకండి'],
        'nav.orders' => ['nav', 'Orders', 'Orders', 'ఆర్డర్లు'],
        'nav.account' => ['nav', 'Account', 'Account', 'ఖాతా'],

        'cart.title' => ['cart', 'Page heading', 'Your cart', 'మీ కార్ట్'],
        'cart.empty' => ['cart', 'Empty cart', 'Your cart is empty.', 'మీ కార్ట్ ఖాళీగా ఉంది.'],
        'cart.empty_hint' => ['cart', 'Empty cart, second line', 'Book a service, or pick something from a shop near you.', 'ఒక సేవను బుక్ చేయండి, లేదా దగ్గరలోని షాప్ నుండి ఏదైనా ఎంచుకోండి.'],
        'cart.browse_services' => ['cart', 'Empty cart button', 'Browse services', 'సేవలు చూడండి'],
        'cart.browse_shop' => ['cart', 'Empty cart button (where the shop is on)', 'Browse the shop', 'షాప్ చూడండి'],
        'cart.services_heading' => ['cart', 'Services row heading', 'Services', 'సేవలు'],
        'cart.services_intro' => ['cart', 'Services row explanation', 'Each box is one visit: one professional does everything in it where possible. Different trades come as separate visits.', 'ప్రతి బాక్స్ ఒక విజిట్: అందులోని పనులన్నీ వీలైనంత వరకు ఒకే నిపుణుడు చేస్తారు. వేర్వేరు రకాల పనులు వేర్వేరు విజిట్‌లుగా వస్తాయి.'],
        'cart.visit_one' => ['cart', 'Visit box count, one service', '1 service · one visit', '1 సేవ · ఒక విజిట్'],
        'cart.visit_many' => ['cart', 'Visit box count, several (:count)', ':count services · one visit', ':count సేవలు · ఒక విజిట్'],
        'cart.note' => ['cart', 'Customer note (:note)', 'Note: :note', 'గమనిక: :note'],
        'cart.estimate_short' => ['cart', 'Under a service price', 'est.', 'అంచనా'],
        'cart.quantity' => ['cart', 'Quantity label', 'Quantity', 'సంఖ్య'],
        'cart.one_less' => ['cart', 'Minus button (screen readers)', 'One less', 'ఒకటి తగ్గించండి'],
        'cart.one_more' => ['cart', 'Plus button (screen readers)', 'One more', 'ఒకటి పెంచండి'],
        'cart.preferred_time' => ['cart', 'Time box label', 'Preferred time (optional)', 'మీకు అనుకూలమైన సమయం (ఐచ్ఛికం)'],
        'cart.remove' => ['cart', 'Remove link', 'Remove', 'తీసివేయండి'],
        'cart.estimated_total' => ['cart', 'Services total label', 'Estimated total', 'అంచనా మొత్తం'],
        'cart.estimate_note' => ['cart', 'Under the services total', 'Estimate only. Your final price is confirmed at checkout.', 'ఇది అంచనా మాత్రమే. చివరి ధర చెక్అవుట్‌లో నిర్ధారించబడుతుంది.'],
        'cart.book_services' => ['cart', 'Services checkout button', 'Book services →', 'సేవలు బుక్ చేయండి →'],
        'cart.shop_heading' => ['cart', 'Shop row heading', 'Shop items', 'షాప్ వస్తువులు'],
        'cart.shop_intro' => ['cart', 'Shop row explanation', 'Each shop is its own order with its own rider.', 'ప్రతి షాప్ ఒక వేరే ఆర్డర్, దానికి వేరే రైడర్.'],
        'cart.closed_now' => ['cart', 'Shop closed badge', 'Closed now', 'ఇప్పుడు మూసి ఉంది'],
        'cart.item_removed' => ['cart', 'A deleted product', 'Item removed', 'వస్తువు తీసివేయబడింది'],
        'cart.item_gone' => ['cart', 'A product taken off sale', 'No longer sold, please remove it', 'ఇది ఇక అమ్మకంలో లేదు, దయచేసి తీసివేయండి'],
        'cart.only_left' => ['cart', 'Stock limit (:count, :item)', 'Only :count of :item can be ordered.', ':item కేవలం :count మాత్రమే ఆర్డర్ చేయవచ్చు.'],
        'cart.items_total' => ['cart', 'Shop items total label', 'Items', 'వస్తువులు'],
        'cart.min_short' => ['cart', 'Below the shop minimum (:amount)', 'Add :amount more to reach this shop\'s minimum order.', 'ఈ షాప్ కనీస ఆర్డర్‌కు ఇంకా :amount జోడించండి.'],
        'cart.free_delivery_short' => ['cart', 'Free delivery progress (:amount)', 'Add :amount more for free delivery (online payment).', 'ఉచిత డెలివరీకి ఇంకా :amount జోడించండి (ఆన్‌లైన్ చెల్లింపు).'],
        'cart.free_delivery_ok' => ['cart', 'Free delivery reached', 'Free delivery from this shop (online payment).', 'ఈ షాప్ నుండి ఉచిత డెలివరీ (ఆన్‌లైన్ చెల్లింపు).'],
        'cart.checkout_shop' => ['cart', 'Shop checkout button (:shop)', 'Checkout :shop →', ':shop చెక్అవుట్ →'],
        'cart.checkout_off' => ['cart', 'Shop checkout button, not ready', 'Checkout', 'చెక్అవుట్'],
        'cart.bar_one' => ['cart', 'Floating bar, one item', '1 item', '1 వస్తువు'],
        'cart.bar_many' => ['cart', 'Floating bar, several (:count)', ':count items', ':count వస్తువులు'],
        'cart.bar_view' => ['cart', 'Floating bar button', 'View cart →', 'కార్ట్ చూడండి →'],
        'cart.back' => ['cart', 'Back link on shop checkout', '← Back to cart', '← కార్ట్‌కు తిరిగి'],
    ];

    /** Old Homepage & sign-up setting key => entry here (that field stays the English value). */
    public const LEGACY = [
        SiteControls::HEADER_PARTNER_LABEL => 'header.partner',
    ];

    public static function teluguEnabled(): bool
    {
        return in_array(strtolower((string) self::raw(self::TELUGU_ENABLED)), ['1', 'true', 'on', 'yes'], true);
    }

    /** The language a customer page is drawn in: their choice (cookie), else the site default; English if Telugu is off. */
    public static function localeFor(?string $chosen): string
    {
        if (! self::teluguEnabled()) {
            return 'en';
        }
        $chosen = in_array($chosen, self::LOCALES, true) ? $chosen : (string) self::raw(self::DEFAULT_LOCALE);

        return in_array($chosen, self::LOCALES, true) ? $chosen : 'en';
    }

    public static function isTelugu(): bool
    {
        return app()->getLocale() === 'te';
    }

    /** The text for $key in the current language, with :name placeholders replaced. Unknown keys return the key. */
    public static function get(string $key, array $replace = []): string
    {
        $entry = self::TEXTS[$key] ?? null;
        if ($entry === null) {
            return $key;
        }
        $en = self::saved($key, 'en') ?? $entry[2];
        $text = self::isTelugu() ? (self::saved($key, 'te') ?? ($entry[3] !== '' ? $entry[3] : $en)) : $en;

        foreach ($replace as $name => $value) {
            $text = str_replace(':'.$name, (string) $value, $text);
        }

        return $text;
    }

    /**
     * For a text that already has its own English field (LEGACY): in English return that field as before; in Telugu
     * return the Telugu value of its entry here.
     */
    public static function legacy(string $settingKey, string $english): string
    {
        $key = self::LEGACY[$settingKey] ?? null;
        if ($key === null || ! self::isTelugu()) {
            return $english;
        }

        return self::saved($key, 'te') ?? (self::TEXTS[$key][3] !== '' ? self::TEXTS[$key][3] : $english);
    }

    /** Setting key that stores $key in $locale; the English side of a LEGACY entry is its old field. */
    public static function settingKey(string $key, string $locale): string
    {
        if ($locale === 'en' && ($old = array_search($key, self::LEGACY, true)) !== false) {
            return $old;
        }

        return "text.{$key}.{$locale}";
    }

    /** The saved (non-blank) value, or null. */
    public static function saved(string $key, string $locale): ?string
    {
        $v = trim((string) self::raw(self::settingKey($key, $locale)));

        return $v !== '' ? $v : null;
    }

    /** Drop this request's cached reads (after the admin screen saves). */
    public static function forget(): void
    {
        foreach (array_keys(request()->attributes->all()) as $k) {
            if (str_starts_with((string) $k, 'site_text:')) {
                request()->attributes->remove($k);
            }
        }
    }

    /** Read once per request (request attributes, like SiteControls, so a save shows on the next request). */
    private static function raw(string $settingKey): ?string
    {
        $bag = request()->attributes;
        $memo = 'site_text:'.$settingKey;
        if (! $bag->has($memo)) {
            $v = Setting::get($settingKey, null);
            $bag->set($memo, $v === null ? null : (string) $v);
        }

        return $bag->get($memo);
    }
}
