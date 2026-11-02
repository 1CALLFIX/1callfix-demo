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
        'shop_checkout' => 'Shop checkout',
        'shop_order' => 'Shop orders & returns',
        'city' => 'City pages',
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

        // Shop go-live (owner 2026-10-31): every customer word on the shop pages.
        'shop.crumb_home' => ['shop', 'Breadcrumb: home', 'Home', 'హోమ్'],
        'shop.crumb_shop' => ['shop', 'Breadcrumb: shop', 'Shop', 'షాప్'],
        'shop.home.kicker' => ['shop', 'Shop front: small line above the heading (:city)', 'Shops of :city', ':city షాపులు'],
        'shop.home.title' => ['shop', 'Shop front: heading', 'Buy from the shops you know, delivered today', 'మీకు తెలిసిన షాపుల నుండి కొనండి, ఈరోజే డెలివరీ'],
        'shop.home.intro' => ['shop', 'Shop front: line under the heading', 'Every order comes from a verified local shop and a 1CallFix rider you can track. Or pick it up yourself.', 'ప్రతి ఆర్డర్ ధృవీకరించిన స్థానిక షాప్ నుండి, మీరు ట్రాక్ చేయగల 1CallFix రైడర్ ద్వారా వస్తుంది. లేదా మీరే వెళ్లి తీసుకోండి.'],
        'shop.home.search_label' => ['shop', 'Shop front: search (screen readers) (:city)', 'Search the shops of :city', ':city షాపుల్లో వెతకండి'],
        'shop.home.search_placeholder' => ['shop', 'Shop front: search hint', 'Search a product or a shop', 'వస్తువు లేదా షాప్ వెతకండి'],
        'shop.home.categories' => ['shop', 'Shop front: category row (screen readers)', 'Categories', 'విభాగాలు'],
        'shop.home.near_you' => ['shop', 'Shop front: shops heading', 'Shops near you', 'మీ దగ్గరలోని షాపులు'],
        'shop.item_one' => ['shop', 'Item count, one', '1 item', '1 వస్తువు'],
        'shop.item_many' => ['shop', 'Item count, several (:count)', ':count items', ':count వస్తువులు'],
        'shop.delivery_fee' => ['shop', 'Delivery fee (:amount)', 'Delivery :amount', 'డెలివరీ :amount'],
        'shop.free_delivery' => ['shop', 'Free delivery', 'Free delivery', 'ఉచిత డెలివరీ'],
        'shop.open_now' => ['shop', 'Shop open badge', 'Open now', 'ఇప్పుడు తెరిచి ఉంది'],
        'shop.closed' => ['shop', 'Shop closed badge (list)', 'Closed', 'మూసి ఉంది'],
        'shop.closed_now' => ['shop', 'Shop closed badge (shop page)', 'Closed now', 'ఇప్పుడు మూసి ఉంది'],
        'shop.home.results_for' => ['shop', 'Search results heading (:term)', 'Results for “:term”', '“:term” ఫలితాలు'],
        'shop.home.everything_in' => ['shop', 'All items heading (:city)', 'Everything in :city', ':city లో అన్నీ'],
        'shop.home.no_match' => ['shop', 'Search found nothing', 'Nothing matches that yet. Try a shorter word.', 'దీనికి సరిపోయేది ఇంకా లేదు. చిన్న పదంతో ప్రయత్నించండి.'],
        'shop.home.coming_soon' => ['shop', 'No products yet (:city)', 'Shops in :city are adding their products. Check back soon.', ':city షాపులు తమ వస్తువులను జోడిస్తున్నాయి. త్వరలో మళ్లీ చూడండి.'],
        'shop.grievance_officer' => ['shop', 'Grievance officer heading', 'Grievance officer', 'ఫిర్యాదుల అధికారి'],
        'shop.store.rating_one' => ['shop', 'Shop rating, one (:avg)', '★ :avg · 1 rating', '★ :avg · 1 రేటింగ్'],
        'shop.store.rating_many' => ['shop', 'Shop rating, several (:avg, :count)', '★ :avg · :count ratings', '★ :avg · :count రేటింగ్‌లు'],
        'shop.store.free_above' => ['shop', 'Free delivery threshold (:amount)', 'free above :amount when paid online', 'ఆన్‌లైన్‌లో చెల్లిస్తే :amount పైన ఉచితం'],
        'shop.store.minimum' => ['shop', 'Minimum order (:amount)', 'Minimum order :amount', 'కనీస ఆర్డర్ :amount'],
        'shop.store.pickup' => ['shop', 'Pickup badge', 'Pickup available', 'షాప్ వద్ద తీసుకోవచ్చు'],
        'shop.store.search_placeholder' => ['shop', 'Shop page search hint (:shop)', 'Search :shop', ':shop లో వెతకండి'],
        'shop.store.search_label' => ['shop', 'Shop page search (screen readers)', 'Search this shop', 'ఈ షాప్‌లో వెతకండి'],
        'shop.store.empty' => ['shop', 'Shop has no items', 'No items here yet.', 'ఇక్కడ ఇంకా వస్తువులు లేవు.'],
        'shop.card.off_online' => ['shop', 'Discount badge on a product (:percent)', ':percent% off · online', ':percent% తగ్గింపు · ఆన్‌లైన్'],
        'shop.out_of_stock' => ['shop', 'Out of stock', 'Out of stock', 'స్టాక్ లేదు'],
        'shop.card.shop_closed' => ['shop', 'Product card: shop closed', 'Shop closed now', 'షాప్ ఇప్పుడు మూసి ఉంది'],
        'shop.card.one_less' => ['shop', 'Minus button (screen readers) (:item)', 'One less :item', ':item ఒకటి తగ్గించండి'],
        'shop.card.one_more' => ['shop', 'Plus button (screen readers) (:item)', 'One more :item', ':item ఒకటి పెంచండి'],
        'shop.card.add' => ['shop', 'Add button', 'Add', 'జోడించండి'],
        'shop.card.choose' => ['shop', 'Product card button when it has sizes/colours/portions', 'Choose', 'ఎంచుకోండి'],
        'shop.sheet.close' => ['shop', 'Choice pop-up: close button (screen readers)', 'Close', 'మూసివేయండి'],
        'shop.sheet.done' => ['shop', 'Choice pop-up: done button after adding', 'Done', 'అయింది'],
        'shop.sheet.details' => ['shop', 'Choice pop-up: link to the product page', 'See full details', 'పూర్తి వివరాలు చూడండి'],
        'shop.card.from' => ['shop', 'Before the lowest price when choices cost different amounts', 'From', 'నుండి'],
        'shop.product.per_unit' => ['shop', 'What one item is, under the price (:unit, e.g. 500 g)', 'Price for :unit', ':unit ధర'],
        'shop.product.pick_first' => ['shop', 'Add pressed before picking a choice (:choice, e.g. size)', 'Choose a :choice first.', 'ముందుగా :choice ఎంచుకోండి.'],
        'shop.product.choice_sold_out' => ['shop', 'The picked choice is sold out (:choice, e.g. M / Red)', ':choice is sold out. Try another.', ':choice అయిపోయింది. మరొకటి ప్రయత్నించండి.'],
        'shop.product.photo' => ['shop', 'Photo thumbnail (screen readers) (:number)', 'Photo :number', 'ఫోటో :number'],
        'shop.product.off_note' => ['shop', 'Discount line on a product (:percent)', ':percent% off when you pay online or from your wallet.', 'ఆన్‌లైన్‌లో లేదా వాలెట్ నుండి చెల్లిస్తే :percent% తగ్గింపు.'],
        'shop.product.only_left' => ['shop', 'Low stock (:count)', 'Only :count left', 'కేవలం :count మిగిలి ఉన్నాయి'],
        'shop.product.in_stock' => ['shop', 'In stock', 'In stock', 'స్టాక్‌లో ఉంది'],
        'shop.product.shop_closed' => ['shop', 'Product page: shop closed', 'The shop is closed right now', 'షాప్ ఇప్పుడు మూసి ఉంది'],
        'shop.product.one_less' => ['shop', 'Product page minus button (screen readers)', 'One less', 'ఒకటి తగ్గించండి'],
        'shop.product.one_more' => ['shop', 'Product page plus button (screen readers)', 'One more', 'ఒకటి పెంచండి'],
        'shop.product.in_bag' => ['shop', 'Quantity in the cart (:count)', ':count in cart', 'కార్ట్‌లో :count'],
        'shop.product.add_to_bag' => ['shop', 'Add to cart button', 'Add to Cart', 'కార్ట్‌లో వేయండి'],
        'shop.product.buy_now' => ['shop', 'Buy now button (straight to checkout)', 'Buy now', 'ఇప్పుడే కొనండి'],
        'shop.product.incl_taxes' => ['shop', 'Under the price', 'Inclusive of all taxes', 'అన్ని పన్నులతో కలిపి'],
        'shop.product.percent_off' => ['shop', 'Discount next to the price (:percent)', ':percent% off', ':percent% తగ్గింపు'],
        'shop.product.select_choice' => ['shop', 'A choice not picked yet, in red (:choice, e.g. size)', 'Please select a :choice', 'దయచేసి :choice ఎంచుకోండి'],
        'shop.product.left' => ['shop', 'Low stock under a size/colour (:count)', ':count left', ':count మిగిలాయి'],
        'shop.promise.delivery' => ['shop', 'Delivery promise (:time, e.g. 6:30 PM)', 'Delivered by about :time today', 'ఈరోజు సుమారు :time కల్లా డెలివరీ'],
        'shop.promise.pickup' => ['shop', 'Pickup promise (:time)', 'Ready to collect by about :time', 'సుమారు :time కల్లా తీసుకోవడానికి సిద్ధం'],
        'shop.product.about' => ['shop', 'Description heading', 'About this item', 'ఈ వస్తువు గురించి'],
        'shop.product.sold_by' => ['shop', 'Trust line 1 (:shop, :city)', 'Sold and packed by :shop, :city.', ':city లోని :shop అమ్మి, ప్యాక్ చేస్తుంది.'],
        'shop.product.delivered_by' => ['shop', 'Trust line 2', 'Delivered by a 1CallFix rider with a code you give at the door, or pick it up at the shop.', 'మీరు ఇంటి వద్ద ఇచ్చే కోడ్‌తో 1CallFix రైడర్ డెలివరీ చేస్తారు, లేదా షాప్ వద్ద తీసుకోండి.'],
        'shop.product.returns' => ['shop', 'Trust line 3 (:days)', 'Damaged or wrong item? Return it within :days days of delivery.', 'దెబ్బతిన్న లేదా తప్పు వస్తువా? డెలివరీ అయిన :days రోజుల్లో తిరిగి ఇవ్వండి.'],
        'shop.product.more_from' => ['shop', 'More from this shop heading (:shop)', 'More from :shop', ':shop నుండి మరిన్ని'],
        'shop_order.list_title' => ['shop_order', 'Shop orders page heading', 'Shop orders', 'షాప్ ఆర్డర్లు'],
        'shop_order.list_empty' => ['shop_order', 'No shop orders', 'No shop orders yet.', 'ఇంకా షాప్ ఆర్డర్లు లేవు.'],
        'shop_order.status.awaiting_payment' => ['shop_order', 'Order status badge: Waiting for payment', 'Waiting for payment', 'చెల్లింపు కోసం వేచి ఉంది'],
        'shop_order.status.sent' => ['shop_order', 'Order status badge: Sent to the shop', 'Sent to the shop', 'షాప్‌కు పంపబడింది'],
        'shop_order.status.accepted' => ['shop_order', 'Order status badge: Accepted', 'Accepted', 'అంగీకరించబడింది'],
        'shop_order.status.packing' => ['shop_order', 'Order status badge: Being packed', 'Being packed', 'ప్యాక్ చేస్తున్నారు'],
        'shop_order.status.ready_collect' => ['shop_order', 'Order status badge: Ready to collect', 'Ready to collect', 'తీసుకోవడానికి సిద్ధం'],
        'shop_order.status.on_the_way' => ['shop_order', 'Order status badge: On the way', 'On the way', 'దారిలో ఉంది'],
        'shop_order.status.finding_rider' => ['shop_order', 'Order status badge: Packed, finding a rider', 'Packed, finding a rider', 'ప్యాక్ అయింది, రైడర్ కోసం చూస్తున్నాం'],
        'shop_order.status.collected' => ['shop_order', 'Order status badge: Collected', 'Collected', 'తీసుకున్నారు'],
        'shop_order.status.delivered' => ['shop_order', 'Order status badge: Delivered', 'Delivered', 'డెలివరీ అయింది'],
        'shop_order.status.cancelled' => ['shop_order', 'Order status badge: Cancelled', 'Cancelled', 'రద్దు చేయబడింది'],
        'shop_checkout.title' => ['shop_checkout', 'Checkout heading', 'Checkout', 'చెక్అవుట్'],
        'shop_checkout.from' => ['shop_checkout', 'Under the heading (:shop)', 'From :shop', ':shop నుండి'],
        'shop_checkout.how' => ['shop_checkout', 'Delivery or pickup heading', 'How do you want it?', 'మీకు ఎలా కావాలి?'],
        'shop_checkout.deliver' => ['shop_checkout', 'Delivery choice', 'Deliver to me', 'నాకు డెలివరీ చేయండి'],
        'shop_checkout.deliver_hint' => ['shop_checkout', 'Delivery choice, second line', 'A 1CallFix rider brings it', '1CallFix రైడర్ తీసుకొస్తారు'],
        'shop_checkout.pickup' => ['shop_checkout', 'Pickup choice', 'I will pick it up', 'నేనే వచ్చి తీసుకుంటాను'],
        'shop_checkout.pickup_hint' => ['shop_checkout', 'Pickup choice, second line', 'Show your code at the shop', 'షాప్ వద్ద మీ కోడ్ చూపించండి'],
        'shop_checkout.no_address' => ['shop_checkout', 'No saved address', 'You have no saved address yet.', 'మీకు ఇంకా సేవ్ చేసిన చిరునామా లేదు.'],
        'shop_checkout.add_address' => ['shop_checkout', 'Add address link', '+ Add an address', '+ చిరునామా జోడించండి'],
        'shop_checkout.collect_from' => ['shop_checkout', 'Pickup explanation (:shop, :address)', 'Collect from :shop, :address. We\'ll tell you when it is packed.', ':shop, :address నుండి తీసుకోండి. ప్యాక్ అయ్యాక మీకు చెబుతాం.'],
        'shop_checkout.pay' => ['shop_checkout', 'Payment heading', 'Pay', 'చెల్లింపు'],
        'shop_checkout.online' => ['shop_checkout', 'Online payment choice', 'UPI, card or netbanking', 'UPI, కార్డ్ లేదా నెట్‌బ్యాంకింగ్'],
        'shop_checkout.online_hint' => ['shop_checkout', 'Online payment, second line', 'Secure Razorpay checkout', 'సురక్షిత Razorpay చెల్లింపు'],
        'shop_checkout.wallet' => ['shop_checkout', 'Wallet choice', 'Wallet', 'వాలెట్'],
        'shop_checkout.wallet_balance' => ['shop_checkout', 'Wallet balance (:amount)', 'Balance :amount', 'బ్యాలెన్స్ :amount'],
        'shop_checkout.wallet_short' => ['shop_checkout', 'Wallet too low', ' — not enough for this order', ' — ఈ ఆర్డర్‌కు సరిపోదు'],
        'shop_checkout.cash_shop' => ['shop_checkout', 'Cash choice, pickup', 'Cash at the shop', 'షాప్ వద్ద నగదు'],
        'shop_checkout.cash_delivery' => ['shop_checkout', 'Cash choice, delivery', 'Cash on delivery', 'డెలివరీ సమయంలో నగదు'],
        'shop_checkout.cash_blocked' => ['shop_checkout', 'Cash not allowed (benefits)', 'Not available: discounted items and coupons are for online payment.', 'అందుబాటులో లేదు: తగ్గింపు వస్తువులు, కూపన్లు ఆన్‌లైన్ చెల్లింపుకు మాత్రమే.'],
        'shop_checkout.cash_pays_fee' => ['shop_checkout', 'Cash loses free delivery', 'Free delivery is for online payment; cash pays the delivery fee.', 'ఉచిత డెలివరీ ఆన్‌లైన్ చెల్లింపుకు మాత్రమే; నగదుతో డెలివరీ ఛార్జీ కట్టాలి.'],
        'shop_checkout.cash_hint' => ['shop_checkout', 'Cash choice, second line', 'Pay when you get it.', 'అందుకున్నప్పుడు చెల్లించండి.'],
        'shop_checkout.note' => ['shop_checkout', 'Note box label', 'Note for the shop', 'షాప్‌కు గమనిక'],
        'shop_checkout.optional' => ['shop_checkout', 'Optional marker', '(optional)', '(ఐచ్ఛికం)'],
        'shop_checkout.note_placeholder' => ['shop_checkout', 'Note box hint', 'For example: ring the bell twice', 'ఉదాహరణ: బెల్ రెండుసార్లు కొట్టండి'],
        'shop_checkout.coupon' => ['shop_checkout', 'Coupon heading', 'Coupon', 'కూపన్'],
        'shop_checkout.you_save' => ['shop_checkout', 'Coupon saving (:amount)', 'you save :amount', 'మీకు :amount ఆదా'],
        'shop_checkout.remove' => ['shop_checkout', 'Remove coupon', 'Remove', 'తీసివేయండి'],
        'shop_checkout.coupon_label' => ['shop_checkout', 'Coupon box (screen readers)', 'Coupon code', 'కూపన్ కోడ్'],
        'shop_checkout.coupon_placeholder' => ['shop_checkout', 'Coupon box hint', 'Enter a coupon code', 'కూపన్ కోడ్ నమోదు చేయండి'],
        'shop_checkout.apply' => ['shop_checkout', 'Apply coupon button', 'Apply', 'వర్తింపజేయండి'],
        'shop_checkout.coupon_online' => ['shop_checkout', 'Coupon rule line', 'Coupons work with online and wallet payment.', 'కూపన్లు ఆన్‌లైన్, వాలెట్ చెల్లింపులకు మాత్రమే.'],
        'shop_checkout.summary' => ['shop_checkout', 'Summary heading', 'Summary', 'సారాంశం'],
        'shop_checkout.items' => ['shop_checkout', 'Items line', 'Items', 'వస్తువులు'],
        'shop_checkout.coupon_line' => ['shop_checkout', 'Coupon line (:code)', 'Coupon :code', 'కూపన్ :code'],
        'shop_checkout.delivery' => ['shop_checkout', 'Delivery line', 'Delivery', 'డెలివరీ'],
        'shop_checkout.free' => ['shop_checkout', 'Free (delivery)', 'Free', 'ఉచితం'],
        'shop_checkout.tax' => ['shop_checkout', 'Tax line', 'Tax', 'పన్ను'],
        'shop_checkout.to_pay' => ['shop_checkout', 'Total to pay', 'To pay', 'చెల్లించాల్సింది'],
        'shop_checkout.place' => ['shop_checkout', 'Place order button (cash)', 'Place order', 'ఆర్డర్ చేయండి'],
        'shop_checkout.pay_amount' => ['shop_checkout', 'Pay button (:amount)', 'Pay :amount', ':amount చెల్లించండి'],
        'shop_checkout.placing' => ['shop_checkout', 'While placing', 'Placing your order…', 'మీ ఆర్డర్ చేస్తున్నాం…'],
        // Shop checkout polish 2026-11-02.
        'shop_checkout.from_shop' => ['shop_checkout', 'Shop address on a delivery order (:shop, :address)', 'From :shop, :address', ':shop, :address నుండి'],
        'shop_checkout.cash_blocked_items' => ['shop_checkout', 'Cash off: discounted items (:items)', 'Not available: :items has an online-only price. Remove it or pay online.', 'అందుబాటులో లేదు: :items ధర ఆన్‌లైన్ చెల్లింపుకు మాత్రమే. దాన్ని తీసివేయండి లేదా ఆన్‌లైన్‌లో చెల్లించండి.'],
        'shop_checkout.cash_blocked_coupon' => ['shop_checkout', 'Cash off: coupon applied', 'Not available with a coupon. Remove the coupon or pay online.', 'కూపన్‌తో అందుబాటులో లేదు. కూపన్ తీసివేయండి లేదా ఆన్‌లైన్‌లో చెల్లించండి.'],
        'shop_checkout.agree' => ['shop_checkout', 'Under the pay button (:terms, :privacy are the two links)', 'By placing your order, you agree to the :terms and :privacy.', 'ఆర్డర్ చేయడం ద్వారా మీరు :terms మరియు :privacyకు అంగీకరిస్తున్నారు.'],
        'shop_checkout.terms' => ['shop_checkout', 'Terms link text under the pay button', 'Terms of Use', 'వినియోగ నిబంధనలు'],
        'shop_checkout.privacy' => ['shop_checkout', 'Privacy link text under the pay button', 'Privacy Policy', 'గోప్యతా విధానం'],
        'shop_checkout.someone_else' => ['shop_checkout', 'Send to someone else (checkbox)', 'Send to someone else', 'వేరొకరికి పంపండి'],
        'shop_checkout.someone_else_hint' => ['shop_checkout', 'Send to someone else, second line', 'The rider calls them, and they get the delivery code by SMS.', 'రైడర్ వారికి కాల్ చేస్తారు, డెలివరీ కోడ్ వారికి SMS ద్వారా వస్తుంది.'],
        'shop_checkout.recipient_name' => ['shop_checkout', 'Receiver name box', 'Their name', 'వారి పేరు'],
        'shop_checkout.recipient_phone' => ['shop_checkout', 'Receiver phone box', 'Their mobile number', 'వారి మొబైల్ నంబర్'],
        'shop_checkout.new_address' => ['shop_checkout', 'New address form heading', 'New address', 'కొత్త చిరునామా'],
        'shop_checkout.address_label' => ['shop_checkout', 'Address name box', 'Name it (Home, Office, Mom…)', 'పేరు పెట్టండి (ఇల్లు, ఆఫీస్, అమ్మ…)'],
        'shop_checkout.address_line' => ['shop_checkout', 'Address box', 'Flat / street / area', 'ఫ్లాట్ / వీధి / ప్రాంతం'],
        'shop_checkout.address_landmark' => ['shop_checkout', 'Landmark box', 'Landmark (optional)', 'గుర్తు (ఐచ్ఛికం)'],
        'shop_checkout.address_pincode' => ['shop_checkout', 'PIN code box', 'PIN code (optional)', 'పిన్ కోడ్ (ఐచ్ఛికం)'],
        'shop_checkout.use_location' => ['shop_checkout', 'Use my location button', 'Use my current location', 'నా ప్రస్తుత లొకేషన్ వాడండి'],
        'shop_checkout.located' => ['shop_checkout', 'Location found (:area)', 'Pinned to your location (:area).', 'మీ లొకేషన్ గుర్తించాం (:area).'],
        'shop_checkout.not_served' => ['shop_checkout', 'Location outside the service area', 'We do not deliver to that spot yet. Type the address instead.', 'ఆ ప్రదేశానికి ఇంకా డెలివరీ లేదు. చిరునామా టైప్ చేయండి.'],
        'shop_checkout.save_address' => ['shop_checkout', 'Save address button', 'Save address', 'చిరునామా సేవ్ చేయండి'],
        'shop_checkout.cancel' => ['shop_checkout', 'Cancel new address', 'Cancel', 'రద్దు'],
        'shop_order.receiver' => ['shop_order', 'Receiver line (:name, :phone)', 'Receiver: :name, :phone', 'అందుకునేవారు: :name, :phone'],
        'shop_checkout.step_address' => ['shop_checkout', 'Step 1 heading', 'Delivery', 'డెలివరీ'],
        'shop_checkout.step_items' => ['shop_checkout', 'Step 2 heading (:count)', 'Your items (:count)', 'మీ వస్తువులు (:count)'],
        'shop_checkout.step_pay' => ['shop_checkout', 'Step 3 heading', 'Payment', 'చెల్లింపు'],
        'shop_checkout.change' => ['shop_checkout', 'Change link', 'Change', 'మార్చండి'],
        'shop_checkout.deliver_to' => ['shop_checkout', 'Chosen address heading (:label)', 'Deliver to :label', ':labelకు డెలివరీ'],
        'shop_checkout.price_details' => ['shop_checkout', 'Price box heading', 'Price details', 'ధర వివరాలు'],
        'shop_checkout.price_mrp' => ['shop_checkout', 'Price before offers (:count items)', 'Price (:count items)', 'ధర (:count వస్తువులు)'],
        'shop_checkout.price_discount' => ['shop_checkout', 'Offer line', 'Offer discount', 'ఆఫర్ తగ్గింపు'],
        'shop_checkout.total' => ['shop_checkout', 'Total line', 'Total', 'మొత్తం'],
        'shop_checkout.saving_banner' => ['shop_checkout', 'Green saving line (:amount)', 'You save :amount on this order', 'ఈ ఆర్డర్‌పై మీకు :amount ఆదా'],
        'shop_checkout.online_saves' => ['shop_checkout', 'Under online / wallet (:amount)', 'Save :amount', ':amount ఆదా'],
        'shop_checkout.cash_full' => ['shop_checkout', 'Under cash when online is cheaper', 'Full price: offers and free delivery are for online payment', 'పూర్తి ధర: ఆఫర్లు, ఉచిత డెలివరీ ఆన్‌లైన్ చెల్లింపుకే'],
        'shop_checkout.no_charge_yet' => ['shop_checkout', 'Under the pay button', 'Safe and secure. You can check your order before paying.', 'సురక్షితం. చెల్లించే ముందు మీ ఆర్డర్ చూసుకోవచ్చు.'],
        'shop_checkout.incl_taxes' => ['shop_checkout', 'Under the total', 'Inclusive of all taxes', 'అన్ని పన్నులతో కలిపి'],
        'cart.saving' => ['cart', 'Green saving line in the cart (:amount)', 'You save :amount with online payment', 'ఆన్‌లైన్ చెల్లింపుతో మీకు :amount ఆదా'],
        'cart.sign_in_checkout' => ['cart', 'Checkout button for a visitor', 'Sign in to checkout', 'చెక్అవుట్‌కు సైన్ ఇన్ చేయండి'],
        'cart.guest_note' => ['cart', 'Under the cart of a visitor', 'Your cart is kept when you sign in.', 'మీరు సైన్ ఇన్ చేసినప్పుడు మీ కార్ట్ అలాగే ఉంటుంది.'],
        'shop_checkout.razorpay_desc' => ['shop_checkout', 'Payment window line (:shop)', 'Order from :shop', ':shop నుండి ఆర్డర్'],
        'shop_order.back' => ['shop_order', 'Back link', '← Shop orders', '← షాప్ ఆర్డర్లు'],
        'shop_order.code_pickup' => ['shop_order', 'Order line, pickup (:code)', 'Order :code · Pickup at shop', 'ఆర్డర్ :code · షాప్ వద్ద తీసుకోవడం'],
        'shop_order.code_delivery' => ['shop_order', 'Order line, delivery (:code)', 'Order :code · Delivery', 'ఆర్డర్ :code · డెలివరీ'],
        'shop_order.step.placed' => ['shop_order', 'Progress: placed', 'Order placed', 'ఆర్డర్ చేశారు'],
        'shop_order.step.accepted' => ['shop_order', 'Progress: accepted', 'Shop accepted', 'షాప్ అంగీకరించింది'],
        'shop_order.step.packing' => ['shop_order', 'Progress: packing', 'Being packed', 'ప్యాక్ చేస్తున్నారు'],
        'shop_order.step.ready_collect' => ['shop_order', 'Progress: ready (pickup)', 'Ready to collect', 'తీసుకోవడానికి సిద్ధం'],
        'shop_order.step.collected' => ['shop_order', 'Progress: done (pickup)', 'Collected', 'తీసుకున్నారు'],
        'shop_order.step.with_rider' => ['shop_order', 'Progress: with the rider', 'With the rider', 'రైడర్ వద్ద ఉంది'],
        'shop_order.step.delivered' => ['shop_order', 'Progress: delivered', 'Delivered', 'డెలివరీ అయింది'],
        'shop_order.cancelled' => ['shop_order', 'Cancelled order line', 'This order was cancelled.', 'ఈ ఆర్డర్ రద్దు చేయబడింది.'],
        'shop_order.cancel_reason' => ['shop_order', 'Cancel reason (:reason)', 'Reason: :reason', 'కారణం: :reason'],
        'shop_order.refund_issued' => ['shop_order', 'Refund line on a cancelled order', 'Refund: issued to where you paid.', 'రీఫండ్: మీరు చెల్లించిన చోటికి పంపబడింది.'],
        'shop_order.unpaid' => ['shop_order', 'Unpaid order heading', 'Payment not received yet.', 'చెల్లింపు ఇంకా అందలేదు.'],
        'shop_order.unpaid_note' => ['shop_order', 'Unpaid order line (:minutes)', 'The shop starts once you pay. Unpaid orders are cancelled after :minutes minutes.', 'మీరు చెల్లించిన తర్వాతే షాప్ మొదలుపెడుతుంది. చెల్లించని ఆర్డర్లు :minutes నిమిషాల తర్వాత రద్దు అవుతాయి.'],
        'shop_order.pay_amount' => ['shop_order', 'Pay button (:amount)', 'Pay :amount', ':amount చెల్లించండి'],
        'shop_order.code_at_shop' => ['shop_order', 'Code box, pickup', 'Show this code at the shop', 'ఈ కోడ్ షాప్ వద్ద చూపించండి'],
        'shop_order.code_to_rider' => ['shop_order', 'Code box, delivery', 'Give this code to your rider', 'ఈ కోడ్ మీ రైడర్‌కు ఇవ్వండి'],
        'shop_order.rider' => ['shop_order', 'Rider name (:name)', 'Rider: :name', 'రైడర్: :name'],
        'shop_order.items' => ['shop_order', 'Items line', 'Items', 'వస్తువులు'],
        'shop_order.coupon_line' => ['shop_order', 'Coupon line (:code)', 'Coupon :code', 'కూపన్ :code'],
        'shop_order.delivery' => ['shop_order', 'Delivery line', 'Delivery', 'డెలివరీ'],
        'shop_order.free' => ['shop_order', 'Free (delivery)', 'Free', 'ఉచితం'],
        'shop_order.tax' => ['shop_order', 'Tax line', 'Tax', 'పన్ను'],
        'shop_order.total' => ['shop_order', 'Total line', 'Total', 'మొత్తం'],
        'shop_order.paid_by' => ['shop_order', 'Paid by line', 'Paid by', 'చెల్లింపు విధానం'],
        'shop_order.paid.online' => ['shop_order', 'Paid by: online', 'Online', 'ఆన్‌లైన్'],
        'shop_order.paid.wallet' => ['shop_order', 'Paid by: wallet', 'Wallet', 'వాలెట్'],
        'shop_order.paid.cash' => ['shop_order', 'Paid by: cash', 'Cash', 'నగదు'],
        'shop_order.to' => ['shop_order', 'Delivery address (:address)', 'To: :address', 'చిరునామా: :address'],
        'shop_order.invoice' => ['shop_order', 'Tax invoice link', 'Tax invoice →', 'పన్ను ఇన్‌వాయిస్ →'],
        'shop_order.why_cancel' => ['shop_order', 'Cancel form question', 'Why are you cancelling?', 'ఎందుకు రద్దు చేస్తున్నారు?'],
        'shop_order.cancel_free' => ['shop_order', 'Cancel before the shop accepts', 'Free: the shop has not accepted yet.', 'ఉచితం: షాప్ ఇంకా అంగీకరించలేదు.'],
        'shop_order.cancel_fee' => ['shop_order', 'Cancel after the shop accepts', 'The shop has accepted; a cancellation fee may apply.', 'షాప్ అంగీకరించింది; రద్దు ఛార్జీ పడవచ్చు.'],
        'shop_order.cancel_btn' => ['shop_order', 'Cancel button', 'Cancel order', 'ఆర్డర్ రద్దు చేయండి'],
        'shop_order.keep' => ['shop_order', 'Keep button', 'Keep it', 'ఉంచండి'],
        'shop_order.cancel_link' => ['shop_order', 'Cancel link', 'Cancel this order', 'ఈ ఆర్డర్ రద్దు చేయండి'],
        'shop_order.you_rated' => ['shop_order', 'Rating given (:shop)', 'You rated :shop', 'మీరు :shop కు రేటింగ్ ఇచ్చారు'],
        'shop_order.how_was' => ['shop_order', 'Rating question (:shop)', 'How was :shop?', ':shop ఎలా ఉంది?'],
        'shop_order.rating' => ['shop_order', 'Stars (screen readers)', 'Rating', 'రేటింగ్'],
        'shop_order.stars' => ['shop_order', 'One star choice (screen readers) (:count)', ':count stars', ':count నక్షత్రాలు'],
        'shop_order.comment_placeholder' => ['shop_order', 'Rating comment hint', 'Anything to tell the shop? (optional)', 'షాప్‌కు ఏమైనా చెప్పాలా? (ఐచ్ఛికం)'],
        'shop_order.send_rating' => ['shop_order', 'Rating button', 'Send rating', 'రేటింగ్ పంపండి'],
        'shop_order.return.requested' => ['shop_order', 'Return status: requested', 'Return requested — we are looking at it.', 'రిటర్న్ కోరారు — మేము పరిశీలిస్తున్నాం.'],
        'shop_order.return.accepted' => ['shop_order', 'Return status: accepted', 'Return accepted. Hand the item back to the shop (or our rider) as we told you.', 'రిటర్న్ అంగీకరించబడింది. మేము చెప్పినట్లు వస్తువును షాప్‌కు (లేదా మా రైడర్‌కు) ఇవ్వండి.'],
        'shop_order.return.declined' => ['shop_order', 'Return status: declined', 'Return not accepted.', 'రిటర్న్ అంగీకరించబడలేదు.'],
        'shop_order.return.received' => ['shop_order', 'Return status: received', 'The shop has the item back. Your refund is being processed.', 'వస్తువు షాప్‌కు చేరింది. మీ రీఫండ్ ప్రాసెస్ అవుతోంది.'],
        'shop_order.return.refunded_original' => ['shop_order', 'Return refunded to the payment (:amount)', 'Refunded :amount to your original payment method.', ':amount మీరు చెల్లించిన విధానానికి రీఫండ్ అయింది.'],
        'shop_order.return.refunded_wallet' => ['shop_order', 'Return refunded to the wallet (:amount)', 'Refunded :amount to your wallet.', ':amount మీ వాలెట్‌కు రీఫండ్ అయింది.'],
        'shop_order.return.rider_coming' => ['shop_order', 'Return pickup waiting for a rider', 'A rider will come to collect it. You pay nothing.', 'ఒక రైడర్ వచ్చి తీసుకెళ్తారు. మీరు ఏమీ చెల్లించనక్కర్లేదు.'],
        'shop_order.return.rider_named' => ['shop_order', 'Return pickup rider on the way (:name)', ':name is coming to collect it. Give the rider this pickup code:', ':name తీసుకెళ్లడానికి వస్తున్నారు. రైడర్‌కు ఈ పికప్ కోడ్ ఇవ్వండి:'],
        'shop_order.return.our_rider' => ['shop_order', 'Rider without a name', 'Our rider', 'మా రైడర్'],
        'shop_order.return.rider_has_it' => ['shop_order', 'Return with the rider', 'The rider has the item and is taking it to the shop.', 'వస్తువు రైడర్ వద్ద ఉంది, షాప్‌కు తీసుకెళ్తున్నారు.'],
        'shop_order.return.ask_line' => ['shop_order', 'Return offer line (:days)', 'Damaged or wrong item? Ask for a return within :days days.', 'దెబ్బతిన్న లేదా తప్పు వస్తువా? :days రోజుల్లో రిటర్న్ కోరండి.'],
        'shop_order.return.link' => ['shop_order', 'Return link / form heading', 'Return an item', 'వస్తువు తిరిగి ఇవ్వండి'],
        'shop_order.return.reason.damaged' => ['shop_order', 'Return reason: damaged', 'Damaged', 'దెబ్బతిన్నది'],
        'shop_order.return.reason.wrong_item' => ['shop_order', 'Return reason: wrong item', 'Wrong item', 'తప్పు వస్తువు'],
        'shop_order.return.ordered' => ['shop_order', 'Ordered quantity (:count)', '(ordered :count)', '(ఆర్డర్ చేసినవి :count)'],
        'shop_order.return.qty_label' => ['shop_order', 'Quantity box (screen readers)', 'Quantity to return', 'తిరిగి ఇచ్చే సంఖ్య'],
        'shop_order.return.what_wrong' => ['shop_order', 'Problem box hint', 'What is wrong with it?', 'దానిలో ఏమి తప్పు ఉంది?'],
        'shop_order.return.photos' => ['shop_order', 'Photos label', 'Photos (1 to 3)', 'ఫోటోలు (1 నుండి 3)'],
        'shop_order.return.submit' => ['shop_order', 'Return submit button', 'Ask for a return', 'రిటర్న్ కోరండి'],
        'shop_order.return.cancel' => ['shop_order', 'Return form cancel', 'Cancel', 'రద్దు'],
        'shop_order.return.rule' => ['shop_order', 'Return rule line (:days)', 'Returns are for damaged or wrong items, within :days days of delivery.', 'రిటర్న్‌లు దెబ్బతిన్న లేదా తప్పు వస్తువులకు మాత్రమే, డెలివరీ అయిన :days రోజుల్లో.'],
        'shop_order.msg.cancel_why' => ['shop_order', 'Cancel without a reason', 'Tell us why, so the shop knows.', 'షాప్‌కు తెలిసేలా కారణం చెప్పండి.'],
        'shop_order.msg.cancelled_fee' => ['shop_order', 'Cancelled with a fee', 'Order cancelled. A cancellation fee applies; the rest is refunded.', 'ఆర్డర్ రద్దయింది. రద్దు ఛార్జీ పడుతుంది; మిగతాది రీఫండ్ అవుతుంది.'],
        'shop_order.msg.cancelled' => ['shop_order', 'Cancelled', 'Order cancelled.', 'ఆర్డర్ రద్దయింది.'],
        'shop_order.msg.refunded_full' => ['shop_order', 'Cancelled, paid order', ' Your money is refunded in full.', ' మీ డబ్బు పూర్తిగా రీఫండ్ అవుతుంది.'],
        'shop_order.msg.return_what' => ['shop_order', 'Return without a description', 'Tell us what is wrong.', 'ఏమి తప్పు ఉందో చెప్పండి.'],
        'shop_order.msg.return_photo' => ['shop_order', 'Return without a photo', 'Add at least one photo of the item.', 'వస్తువు ఫోటో కనీసం ఒకటి జోడించండి.'],
        'shop_order.msg.return_sent' => ['shop_order', 'Return requested', 'Return requested. We will look at it and tell you what happens next.', 'రిటర్న్ కోరారు. మేము పరిశీలించి తర్వాత ఏమి జరుగుతుందో చెబుతాం.'],
        'shop_order.msg.tap_star' => ['shop_order', 'Rating without a star', 'Tap a star.', 'ఒక నక్షత్రం నొక్కండి.'],
        'shop_order.msg.thanks_rating' => ['shop_order', 'Rating saved (:shop)', 'Thanks for rating :shop.', ':shop కు రేటింగ్ ఇచ్చినందుకు ధన్యవాదాలు.'],
        'shop_checkout.coupon_applied' => ['shop_checkout', 'Coupon applied', 'Coupon applied.', 'కూపన్ వర్తించింది.'],
        'shop_checkout.coupon_removed_cash' => ['shop_checkout', 'Coupon removed on cash', 'Coupons are for online or wallet payment, so it was removed.', 'కూపన్లు ఆన్‌లైన్ లేదా వాలెట్ చెల్లింపుకు మాత్రమే, కాబట్టి తీసివేయబడింది.'],
        'shop_checkout.choose_address' => ['shop_checkout', 'No delivery address chosen', 'Choose where to deliver, or pick it up at the shop.', 'ఎక్కడ డెలివరీ చేయాలో ఎంచుకోండి, లేదా షాప్ వద్ద తీసుకోండి.'],
        'shop.item_gone' => ['shop', 'Item no longer sold', 'This item is no longer available.', 'ఈ వస్తువు ఇక అందుబాటులో లేదు.'],
        'shop.meta.title' => ['shop', 'Shop front: browser title (:city)', 'Shop local in :city', ':city లో స్థానికంగా కొనండి'],
        'shop.meta.description' => ['shop', 'Shop front: search-engine description (:city, :brand)', 'Order from local shops in :city on :brand — delivered by our riders or ready for pickup.', ':brand లో :city స్థానిక షాపుల నుండి ఆర్డర్ చేయండి — మా రైడర్లు డెలివరీ చేస్తారు లేదా మీరే తీసుకోండి.'],
        'shop.meta.store_description' => ['shop', 'Shop page: search-engine description when the shop has none (:shop, :city, :brand)', 'Order from :shop in :city on :brand.', ':brand లో :city లోని :shop నుండి ఆర్డర్ చేయండి.'],

        // City URL architecture (2026-11-01): the homepage shop rows.
        'home.shop_categories.title' => ['home', 'Shop categories row: heading', 'Shop by category', 'విభాగాల వారీగా కొనండి'],
        'home.shops.title' => ['home', 'Shops row: heading', 'Shops near you', 'మీ దగ్గరి షాపులు'],
        'home.shops.line' => ['home', 'Shops row: line (:city)', 'Local shops in :city, delivered by our riders.', ':city లోని స్థానిక షాపులు, మా రైడర్లు డెలివరీ చేస్తారు.'],
        'home.shop_products.title' => ['home', 'Shop products row: heading', 'From local shops', 'స్థానిక షాపుల నుండి'],
        'home.shop_products.line' => ['home', 'Shop products row: line (:city)', 'Order today from shops in :city.', ':city లోని షాపుల నుండి ఈరోజే ఆర్డర్ చేయండి.'],

        // City URL architecture (2026-11-01): /{city}, /{city}/services and /{city}/parcel.
        'city.crumb_home' => ['city', 'Breadcrumb: home', 'Home', 'హోమ్'],
        'city.crumb_services' => ['city', 'Breadcrumb: services', 'Services', 'సేవలు'],
        'city.crumb_parcel' => ['city', 'Breadcrumb: parcel', 'Parcel', 'పార్సిల్'],
        'city.hub.title' => ['city', 'City hub: heading (:brand, :city)', ':brand in :city', ':city లో :brand'],
        'city.hub.intro' => ['city', 'City hub: line under the heading (:city)', 'Everything we do in :city, in one place.', ':city లో మేము చేసేవన్నీ, ఒకే చోట.'],
        'city.hub.meta' => ['city', 'City hub: search-engine description (:brand, :city)', 'Home services, local shops and parcel delivery in :city with :brand.', ':brand తో :city లో ఇంటి సేవలు, స్థానిక షాపులు మరియు పార్సిల్ డెలివరీ.'],
        'city.hub.empty' => ['city', 'City hub: nothing live yet (:city)', 'We are getting ready in :city. Please check back soon.', ':city లో మేము సిద్ధమవుతున్నాం. త్వరలో మళ్ళీ చూడండి.'],
        'city.parcel.title' => ['city', 'Parcel page: heading (:city)', 'Parcel delivery in :city', ':city లో పార్సిల్ డెలివరీ'],
        'city.parcel.intro' => ['city', 'Parcel page: line under the heading (:brand, :city)', 'A :brand rider picks it up and drops it anywhere in :city. Track it live.', ':brand రైడర్ తీసుకుని :city లో ఎక్కడికైనా అందిస్తారు. లైవ్‌గా ట్రాక్ చేయండి.'],
        'city.parcel.from' => ['city', 'Parcel page: starting price (:price)', 'From :price', ':price నుండి'],
        'city.parcel.send' => ['city', 'Parcel page: send button', 'Send a parcel', 'పార్సిల్ పంపండి'],
        'city.parcel.mine' => ['city', 'Parcel page: my parcels link', 'My parcels', 'నా పార్సిల్స్'],
        'city.parcel.meta' => ['city', 'Parcel page: search-engine description (:city, :brand)', 'Send keys, documents, lunch boxes and more across :city with :brand. Live tracking, OTP at drop.', ':brand తో :city లో తాళాలు, పత్రాలు, లంచ్ బాక్స్‌లు మరియు మరెన్నో పంపండి. లైవ్ ట్రాకింగ్, డ్రాప్ వద్ద OTP.'],
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
