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
        'location' => 'Location picker',
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

        'search.products' => ['header', 'Search box: shop items group', 'Shop items', 'షాప్ వస్తువులు'],
        'search.shops' => ['header', 'Search box: shops group', 'Shops', 'షాపులు'],
        'search.open_now' => ['header', 'Search box: shop open badge', 'Open now', 'ఇప్పుడు తెరిచి ఉంది'],

        'location.set' => ['location', 'Location button, nothing chosen yet', 'Set location', 'ప్రాంతం ఎంచుకోండి'],
        'location.title' => ['location', 'Location window heading', 'Where do you need service?', 'మీకు ఎక్కడ సేవ కావాలి?'],
        'location.intro' => ['location', 'Location window line', 'Choose your area so we can show what\'s available near you.', 'మీ దగ్గర ఏమి అందుబాటులో ఉందో చూపించడానికి మీ ప్రాంతాన్ని ఎంచుకోండి.'],
        'location.close' => ['location', 'Close button (screen readers)', 'Close', 'మూసివేయండి'],
        'location.use_mine' => ['location', 'Use my location button', 'Use my current location', 'నా ప్రస్తుత లొకేషన్ వాడండి'],
        'location.finding' => ['location', 'While finding the location', 'Finding your location…', 'మీ లొకేషన్ కనుగొంటున్నాం…'],
        'location.failed' => ['location', 'Location not found', 'We couldn\'t get your location. Please choose an area below instead.', 'మీ లొకేషన్ దొరకలేదు. దయచేసి కింద ఒక ప్రాంతాన్ని ఎంచుకోండి.'],
        'location.place_label' => ['location', 'Place search (screen readers)', 'Search for your location, society or apartment', 'మీ లొకేషన్, సొసైటీ లేదా అపార్ట్‌మెంట్ వెతకండి'],
        'location.place_placeholder' => ['location', 'Place search hint', 'Search for your location / society / apartment', 'మీ లొకేషన్ / సొసైటీ / అపార్ట్‌మెంట్ వెతకండి'],
        'location.recent' => ['location', 'Recent locations heading', 'Recent locations', 'ఇటీవలి లొకేషన్లు'],
        'location.zone_label' => ['location', 'Area search (screen readers)', 'Search areas', 'ప్రాంతాలు వెతకండి'],
        'location.zone_placeholder' => ['location', 'Area search hint', 'Search by area or city', 'ప్రాంతం లేదా నగరం పేరుతో వెతకండి'],
        'location.selected' => ['location', 'Chosen area badge', 'Selected', 'ఎంచుకున్నది'],
        'location.no_match' => ['location', 'No area matches (:search)', 'No areas match ":search".', '":search" కు సరిపోయే ప్రాంతాలు లేవు.'],
        'location.none' => ['location', 'No areas at all', 'No service areas are available yet.', 'ఇంకా సేవా ప్రాంతాలు అందుబాటులో లేవు.'],

        'nav.home' => ['nav', 'Home', 'Home', 'హోమ్'],
        'nav.services' => ['nav', 'Services', 'Services', 'సేవలు'],
        'nav.shop' => ['nav', 'Shop (only where the shop is on)', 'Shop', 'షాప్'],
        'nav.search' => ['nav', 'Search (where the shop is off)', 'Search', 'వెతకండి'],
        'nav.orders' => ['nav', 'Orders', 'Orders', 'ఆర్డర్లు'],
        'nav.account' => ['nav', 'Account', 'Account', 'ఖాతా'],

        'home.explore_title' => ['home', 'Doors row heading (:brand = platform name)', 'Explore :brand', ':brand లో చూడండి'],
        'home.promise' => ['home', 'Line under the doors heading (switch on Homepage sections)', 'Trusted local pros, nearby shops and parcel delivery, in one app.', 'నమ్మకమైన స్థానిక నిపుణులు, దగ్గరి షాపులు, పార్సిల్ డెలివరీ — అన్నీ ఒకే యాప్‌లో.'],
        'home.door.services.title' => ['home', 'Services door: title', 'Services', 'సేవలు'],
        'home.door.services.line' => ['home', 'Services door: line', 'Book trusted pros', 'నమ్మకమైన నిపుణులను బుక్ చేయండి'],
        'home.door.services.live' => ['home', 'Services door: live line (:count services listed)', ':count services to book', 'బుక్ చేయడానికి :count సేవలు'],
        'home.door.services.live_one' => ['home', 'Services door: live line, one service', '1 service to book', 'బుక్ చేయడానికి 1 సేవ'],
        'home.door.parcel.title' => ['home', 'Parcel door: title', 'Parcel', 'పార్సిల్'],
        'home.door.parcel.line' => ['home', 'Parcel door: line', 'Send anything across town', 'ఊరిలో ఎక్కడికైనా ఏదైనా పంపండి'],
        'home.door.parcel.live' => ['home', 'Parcel door: live line (:price = base fare)', 'From :price', ':price నుండి'],
        'home.door.parcel.live_one' => ['home', 'Parcel door: (not used)', 'From :price', ':price నుండి'],
        'home.door.shop.title' => ['home', 'Shop door: title', 'Shop', 'షాప్'],
        'home.door.shop.line' => ['home', 'Shop door: line', 'Local shops, fast delivery', 'దగ్గరి షాపులు, వేగంగా డెలివరీ'],
        'home.door.shop.live' => ['home', 'Shop door: live line (:count shops open now)', ':count shops open now', 'ఇప్పుడు :count షాపులు తెరిచి ఉన్నాయి'],
        'home.door.shop.live_one' => ['home', 'Shop door: live line, one shop open', '1 shop open now', 'ఇప్పుడు 1 షాప్ తెరిచి ఉంది'],
        'home.cards.view_all' => ['home', 'Business cards: "View all" link', 'View all', 'అన్నీ చూడండి'],
        'home.cards.more' => ['home', 'Business cards: "More" button', 'More', 'మరిన్ని'],
        'home.cards.new' => ['home', 'Business cards: badge on a new icon', 'New', 'కొత్తది'],
        'home.card.offer_line' => ['home', 'Business card: line when a category has a live offer (:name = category)', 'Offers on now in :name', ':name లో ఇప్పుడు ఆఫర్లు'],
        'home.card.parcel.title' => ['home', 'Parcel card: heading', 'Parcel & delivery', 'పార్సిల్ & డెలివరీ'],
        'home.card.parcel.line' => ['home', 'Parcel card: line (no price set)', 'Pickup and drop across town', 'ఊరిలో పికప్ మరియు డ్రాప్'],
        'home.card.parcel.line_price' => ['home', 'Parcel card: line (:price = base fare)', 'Send anything across town, from :price', 'ఊరిలో ఏదైనా పంపండి, :price నుండి'],
        'home.card.parcel.send' => ['home', 'Parcel card: icon, send now', 'Send now', 'ఇప్పుడే పంపండి'],
        'home.card.parcel.schedule' => ['home', 'Parcel card: icon, schedule a pickup', 'Schedule pickup', 'పికప్ షెడ్యూల్'],
        'home.card.parcel.track' => ['home', 'Parcel card: icon, my parcels', 'My parcels', 'నా పార్సిళ్లు'],
        'home.card.shop.title' => ['home', 'Shop card: heading', 'Shop near you', 'మీ దగ్గరి షాపులు'],
        'home.card.shop.line' => ['home', 'Shop card: line (no shop open)', 'Order from local shops', 'దగ్గరి షాపుల నుండి ఆర్డర్ చేయండి'],
        'home.card.shop.live' => ['home', 'Shop card: line (:count shops open now)', ':count shops open now, order in a tap', 'ఇప్పుడు :count షాపులు తెరిచి ఉన్నాయి, ఒక్క ట్యాప్‌లో ఆర్డర్'],
        'home.card.shop.live_one' => ['home', 'Shop card: line, one shop open', '1 shop open now, order in a tap', 'ఇప్పుడు 1 షాప్ తెరిచి ఉంది, ఒక్క ట్యాప్‌లో ఆర్డర్'],
        'home.categories.sr' => ['home', 'Category icons (screen readers)', 'Browse by category', 'విభాగాల వారీగా చూడండి'],
        'home.categories.empty_title' => ['home', 'No categories yet: heading', 'No categories published yet', 'ఇంకా విభాగాలు ప్రచురించలేదు'],
        'home.categories.empty_line' => ['home', 'No categories yet: line', 'Service categories will appear here once they are published from the admin panel.', 'అడ్మిన్ ప్యానెల్ నుండి ప్రచురించిన తర్వాత సేవా విభాగాలు ఇక్కడ కనిపిస్తాయి.'],
        'home.see_all' => ['home', 'Section headings: "See all" link', 'See all', 'అన్నీ చూడండి'],
        'home.new.title' => ['home', 'New services row: heading', 'New & noteworthy', 'కొత్తవి & ప్రత్యేకమైనవి'],
        'home.new.line' => ['home', 'New services row: line', 'The latest additions to the catalogue.', 'తాజాగా చేర్చిన సేవలు.'],
        'home.booked.title' => ['home', 'Most booked row: heading', 'Most booked services', 'ఎక్కువగా బుక్ చేసిన సేవలు'],
        'home.booked.line_area' => ['home', 'Most booked row: line (:area = chosen area)', 'What customers around :area book most often.', ':area చుట్టుపక్కల కస్టమర్లు ఎక్కువగా బుక్ చేసేవి.'],
        'home.booked.line' => ['home', 'Most booked row: line (no area chosen)', 'What customers book most often.', 'కస్టమర్లు ఎక్కువగా బుక్ చేసేవి.'],
        'home.offers.title' => ['home', 'Offers row: heading', 'Offers on now', 'ఇప్పుడు ఉన్న ఆఫర్లు'],
        'home.offers.line' => ['home', 'Offers row: line', 'Limited-time pricing, while it lasts.', 'పరిమిత కాల ధరలు, ఉన్నంత వరకే.'],
        'home.banner.mid' => ['home', 'Middle banners (screen readers)', 'Offers and announcements', 'ఆఫర్లు మరియు ప్రకటనలు'],
        'home.membership.title' => ['home', 'Membership box: heading (:brand = platform name)', ':brand membership', ':brand మెంబర్‌షిప్'],
        'home.membership.line' => ['home', 'Membership box: line', 'Membership plans available on your account.', 'మీ ఖాతాకు అందుబాటులో ఉన్న మెంబర్‌షిప్ ప్లాన్లు.'],
        'home.membership.button' => ['home', 'Membership box: button', 'About membership', 'మెంబర్‌షిప్ గురించి'],
        'home.trust.title' => ['home', 'Why choose us: heading (:brand = platform name)', 'Why choose :brand', ':brand నే ఎందుకు ఎంచుకోవాలి'],
        'home.trust.1.title' => ['home', 'Why choose us: point 1 heading', 'Verified professionals', 'ధృవీకరించిన నిపుణులు'],
        'home.trust.1.body' => ['home', 'Why choose us: point 1 text', 'Every professional is onboarded through document verification before taking work.', 'ప్రతి నిపుణుడు పని తీసుకునే ముందు డాక్యుమెంట్ల ధృవీకరణతో చేరతారు.'],
        'home.trust.2.title' => ['home', 'Why choose us: point 2 heading', 'Transparent pricing', 'స్పష్టమైన ధరలు'],
        'home.trust.2.body' => ['home', 'Why choose us: point 2 text', 'Prices are set by us, shown before you book, and calculated on our servers — never guessed.', 'ధరలు మేమే నిర్ణయిస్తాం, బుక్ చేసే ముందే చూపిస్తాం, మా సర్వర్లలోనే లెక్కిస్తాం — ఊహించి కాదు.'],
        'home.trust.3.title' => ['home', 'Why choose us: point 3 heading', 'One-time code security', 'వన్-టైమ్ కోడ్ భద్రత'],
        'home.trust.3.body' => ['home', 'Why choose us: point 3 text', 'Jobs start and finish only when you share your one-time code with the professional.', 'మీరు మీ వన్-టైమ్ కోడ్ నిపుణుడికి చెప్పినప్పుడే పని మొదలై పూర్తవుతుంది.'],
        'home.trust.4.title' => ['home', 'Why choose us: point 4 heading', 'Support when you need it', 'అవసరమైనప్పుడు సహాయం'],
        'home.trust.4.body' => ['home', 'Why choose us: point 4 text', 'Reach a real person through the help centre if something is not right.', 'ఏదైనా సరిగా లేకపోతే హెల్ప్ సెంటర్ ద్వారా నిజమైన వ్యక్తిని సంప్రదించండి.'],
        'home.faq.title' => ['home', 'Questions: heading', 'Frequently asked questions', 'తరచుగా అడిగే ప్రశ్నలు'],
        'home.faq.still' => ['home', 'Questions: line before the help link', 'Still stuck?', 'ఇంకా సందేహమా?'],
        'home.faq.help' => ['home', 'Questions: help centre link', 'Visit the help centre', 'హెల్ప్ సెంటర్‌కు వెళ్లండి'],

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
