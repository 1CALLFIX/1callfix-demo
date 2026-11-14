<?php

namespace App\Support\InfoPages;

use App\Models\Provider;
use App\Models\Service;
use App\Models\Setting;
use App\Services\ModuleActivationService;
use App\Support\Modules;

/**
 * The public /franchise page (owner 2026-11-07: "fully controlled by Super Admin only", attractive, trustable,
 * informative). Every word, list, picture and switch on it lives here: defaults in code, the Super Admin's version in
 * ONE settings key (`franchise_page.content`, JSON, audit-logged by SettingsAuditor) edited on Admin → Website & Pages →
 * Franchise page, which only a Super Admin can open.
 *
 * Trust rules for the defaults: nothing invented. No investment amounts, returns, payback periods, franchise counts or
 * testimonials are shipped — those blocks stay hidden until the Super Admin fills them in. The "live numbers" strip is
 * read from the database (services on sale, businesses live, approved partners) and a number that is 0 is not shown.
 *
 * Applying is NOT a second sign-up (owner rule: every partner joins through /partners): the page's buttons open the
 * /partners form with the "Franchise owner" card picked, and the lead lands in Admin → Partner leads.
 */
final class FranchisePage
{
    public const CONTENT = 'franchise_page.content';
    public const ENABLED = 'franchise_page.enabled';
    public const ACCEPTING = 'franchise_page.accepting';
    public const ROLE = 'franchise';

    /** Icons an admin can pick for a "why" tile (all exist in components/icon). */
    public const ICONS = ['squares', 'bolt', 'map-pin', 'banknotes', 'shield', 'users', 'chart-bar', 'sparkles', 'trophy', 'chat', 'megaphone', 'building', 'check-circle', 'star', 'wallet', 'clock'];

    /** Text fields: key => [admin label, max length]. */
    public const TEXT = [
        'hero_badge' => ['Hero: small label', 60],
        'hero_title' => ['Hero: heading', 120],
        'hero_subtitle' => ['Hero: line under the heading', 320],
        'cta_label' => ['Main button', 40],
        'call_label' => ['"Talk to us" button (shown when Contact page has a phone or WhatsApp)', 40],
        'why_title' => ['"Why" section heading', 80],
        'why_intro' => ['"Why" section line', 300],
        'biz_title' => ['"Every business" section heading (shows the businesses live on the app)', 80],
        'biz_intro' => ['"Every business" section line', 300],
        'biz_live' => ['"Every business" section: badge on each business', 20],
        'note_service' => ['"Every business" card line: Service', 200],
        'note_parcel' => ['"Every business" card line: Parcel Delivery', 200],
        'note_rental' => ['"Every business" card line: Rental', 200],
        'note_food' => ['"Every business" card line: Food Delivery', 200],
        'note_grocery' => ['"Every business" card line: Grocery', 200],
        'note_pharmacy' => ['"Every business" card line: Pharmacy', 200],
        'note_commerce' => ['"Every business" card line: E-commerce', 200],
        'note_taxi' => ['"Every business" card line: Taxi', 200],
        'note_hotel' => ['"Every business" card line: Hotel / Stay', 200],
        'note_events' => ['"Every business" card line: Events', 200],
        'plans_title' => ['Franchise plans: heading', 80],
        'plans_intro' => ['Franchise plans: line under the heading', 300],
        'plans_badge' => ['Franchise plans: offer label (e.g. Founding partner offer)', 60],
        'plans_note' => ['Franchise plans: offer small print', 300],
        'plans_royalty_label' => ['Franchise plans: word for royalty', 30],
        'plans_term_label' => ['Franchise plans: word for the term', 30],
        'pick_title' => ['Business picker: heading', 80],
        'pick_intro' => ['Business picker: line', 300],
        'fee_all' => ['Business picker: fee for ALL businesses, in rupees (numbers only; empty = picker hidden)', 12],
        'fee_two' => ['Business picker: fee for any TWO businesses, in rupees (numbers only)', 12],
        'fee_addon' => ['Business picker: fee for each business added after two, in rupees (numbers only)', 12],
        'fee_one' => ['Business picker: fee for ONE business on its own, in rupees (empty = "talk to our team")', 12],
        'pick_one_note' => ['Business picker: shown for one business when it has no fee', 160],
        'pick_result' => ['Business picker: label over the fee', 60],
        'pay_title' => ['Payment steps heading', 80],
        'areas_title' => ['Area table heading', 80],
        'compare_title' => ['Comparison table heading', 80],
        'compare_them' => ['Comparison table: left column heading', 40],
        'compare_us' => ['Comparison table: right column heading', 40],
        'calc_title' => ['Earnings calculator heading', 80],
        'calc_share' => ['Earnings calculator: your share of each order, in % (empty = calculator hidden)', 6],
        'calc_orders' => ['Earnings calculator: orders slider label', 60],
        'calc_value' => ['Earnings calculator: order value slider label', 60],
        'calc_share_label' => ['Earnings calculator: share line label', 60],
        'calc_result' => ['Earnings calculator: result heading', 80],
        'calc_note' => ['Earnings calculator: small print under it', 300],
        'support_title' => ['"What you get" heading', 80],
        'earn_title' => ['"How you earn" heading', 80],
        'earn_text' => ['"How you earn" text', 600],
        'invest_title' => ['Investment box heading', 80],
        'invest_note' => ['Investment box note (shown under the figures, or alone when there are none)', 400],
        'ideal_title' => ['"Who we look for" heading', 80],
        'steps_title' => ['Steps heading', 80],
        'cities_title' => ['Cities heading', 80],
        'cities_note' => ['Cities note', 300],
        'stories_title' => ['Partner stories heading', 80],
        'faq_title' => ['FAQ heading', 80],
        'final_title' => ['Closing band: heading', 120],
        'final_text' => ['Closing band: line', 300],
        'disclaimer' => ['Small print at the bottom', 600],
        'apply_done_title' => ['After applying: heading (on /partners)', 120],
        'apply_done_body' => ['After applying: text (on /partners)', 400],
        'card_label' => ['Card name on /partners', 40],
        'card_blurb' => ['"Franchise owner" card on /partners: line', 160],
        'seo_title' => ['Page title (Google, browser tab)', 120],
        'seo_description' => ['Page description (Google)', 320],
    ];

    /** Lists: key => [admin label, max items, fields => max length]. */
    public const LISTS = [
        'highlights' => ['Hero: tick lines', 4, ['text' => 80]],
        'stats' => ['Your own figures (value + label), shown beside the live numbers', 4, ['value' => 20, 'label' => 60]],
        'why' => ['"Why" tiles', 8, ['icon' => 20, 'title' => 80, 'text' => 260]],
        'plans' => ['Franchise plans (name, old fee, offer fee, royalty, term, what it covers)', 4, ['name' => 60, 'was' => 30, 'price' => 30, 'royalty' => 20, 'validity' => 30, 'text' => 160]],
        'payment' => ['Payment steps (title + text)', 4, ['title' => 60, 'text' => 200]],
        'areas' => ['Area table (franchise type, city population, exclusive area)', 6, ['type' => 60, 'population' => 60, 'radius' => 60]],
        'compare' => ['Comparison rows (what / one-business franchise / 1CallFix franchise)', 8, ['label' => 60, 'them' => 120, 'us' => 160]],
        'support' => ['"What you get" lines', 10, ['text' => 140]],
        'invest' => ['Investment figures (label + value), e.g. "Investment" / "₹…"', 6, ['label' => 60, 'value' => 60]],
        'ideal' => ['"Who we look for" lines', 8, ['text' => 140]],
        'steps' => ['Steps', 7, ['title' => 60, 'text' => 220]],
        'cities' => ['Cities open for franchise', 30, ['name' => 60]],
        'stories' => ['Partner stories (only real ones)', 6, ['name' => 60, 'city' => 60, 'quote' => 400]],
        'faq' => ['Questions and answers', 15, ['q' => 160, 'a' => 800]],
    ];

    /** List fields that may be left empty (e.g. an add-on plan has no royalty or term of its own). */
    public const OPTIONAL_FIELDS = ['plans.was', 'plans.royalty', 'plans.validity', 'plans.text'];

    /** Switches: key => [admin label, default]. */
    public const SWITCHES = [
        'show_live_stats' => ['Show live numbers from the app (services, businesses live, approved partners)', true],
        'show_contact' => ['Show phone / WhatsApp / email from the Contact page', true],
    ];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'hero_badge' => 'Franchise opportunity',
            'hero_title' => 'Bring 1CallFix to your city',
            'hero_subtitle' => 'Run your city\'s home services, food delivery, shopping and parcel delivery on one app, with the technology, brand and online payments already built.',
            'cta_label' => 'Apply for a franchise',
            'call_label' => 'Talk to our team',
            'why_title' => 'Why a 1CallFix franchise',
            'why_intro' => 'You bring local know-how and a team. We bring the apps, the systems and the rules that keep customers and partners safe.',
            'biz_title' => 'Every business, one franchise',
            'biz_intro' => 'Most franchises give you one business. Yours runs every business live on the 1CallFix app in your city, from one login.',
            'biz_live' => 'Live',
            'note_service' => 'Every home needs AC, plumbing, electrical and appliance work, every season, again and again.',
            'note_parcel' => 'Shops, offices and families send things across town every day. Same-day delivery keeps growing.',
            'note_rental' => 'Function halls, homes, vehicles and equipment: big bookings, with your share on every one.',
            'note_food' => 'People order food online more every year, and local restaurants want those orders.',
            'note_grocery' => 'Daily needs that every family buys every week.',
            'note_pharmacy' => 'Medicines delivered home: a daily need for every family.',
            'note_commerce' => 'Local shops get an online store, and your city shops from home.',
            'note_taxi' => 'Rides across town, every day.',
            'note_hotel' => 'Rooms for visitors, weddings and business trips into your city.',
            'note_events' => 'Tickets for shows, concerts and local events, sold online.',
            'plans_title' => 'Pick the franchise that fits you',
            'plans_intro' => 'Run every business in your city, or start with the ones you know best and add more later.',
            'plans_badge' => 'Founding partner offer',
            'plans_note' => 'Offer valid for the first 10 franchise sign-ups only. Fees and royalty are confirmed in your written franchise agreement.',
            'plans_royalty_label' => 'Royalty',
            'plans_term_label' => 'Term',
            'pick_title' => 'Build your own franchise',
            'pick_intro' => 'Tap the businesses you want to run in your city. The fee updates as you pick.',
            'fee_all' => '550000',
            'fee_two' => '350000',
            'fee_addon' => '100000',
            'fee_one' => '',
            'pick_one_note' => 'One business on its own: talk to our team for the fee.',
            'pick_result' => 'Your franchise fee (launch offer)',
            'pay_title' => 'Easy payment',
            'areas_title' => 'Your area, only yours',
            'compare_title' => 'One business, or all of them?',
            'compare_them' => 'A one-business franchise',
            'compare_us' => '1CallFix franchise',
            'calc_title' => 'Work out your monthly share',
            'calc_share' => '',
            'calc_orders' => 'Orders a day in your city',
            'calc_value' => 'Average order value',
            'calc_share_label' => 'Your share of every completed order',
            'calc_result' => 'Your share in a month (estimate)',
            'calc_note' => 'An estimate to help you plan, not a promise of income. Your real share, fees and territory are written in your franchise agreement.',
            'support_title' => 'What you get from day one',
            'earn_title' => 'How you earn',
            'earn_text' => 'You earn a share of every completed booking and order in your city. Your share, fees and territory are written in your franchise agreement, and your earnings show live in your own Franchise Earnings report.',
            'invest_title' => 'Investment and returns',
            'invest_note' => 'Investment and expected returns depend on the city. We share them in writing on your call with our team, together with the franchise agreement, before you commit to anything.',
            'ideal_title' => 'Who we are looking for',
            'steps_title' => 'From application to launch',
            'cities_title' => 'Cities open for franchise',
            'cities_note' => 'Don\'t see your city? Apply anyway and tell us where. We open new cities as we find the right partner.',
            'stories_title' => 'From our franchise partners',
            'faq_title' => 'Questions franchise applicants ask',
            'final_title' => 'Ready to bring 1CallFix to your city?',
            'final_text' => 'Apply in two minutes. Our team calls you to talk through your city, the agreement and the next steps. Applying is free and does not commit you to anything.',
            'disclaimer' => 'Applying does not create a franchise or any obligation for either side. A franchise is granted only by a signed written agreement with 1CallFix, after both sides have agreed the city, terms and fees.',
            'apply_done_title' => 'Thank you. Your franchise application is in.',
            'apply_done_body' => 'Our franchise team will call you on this number to talk about your city and the next steps.',
            'card_label' => 'Franchise owner',
            'card_blurb' => 'Run 1CallFix in your city: services, food, shop and parcel on one app.',
            'seo_title' => 'Franchise opportunity: bring 1CallFix to your city',
            'seo_description' => 'Run home services, food delivery, shopping and parcel delivery in your city on the 1CallFix app. Technology, brand and online payments ready. Apply in two minutes.',

            'highlights' => [
                ['text' => 'One app, many businesses'],
                ['text' => 'Technology ready on day one'],
                ['text' => 'Your city, your local team'],
            ],
            'stats' => [],
            'why' => [
                ['icon' => 'squares', 'title' => 'Many businesses, one app', 'text' => 'Home services, food delivery, shopping and parcel delivery in one customer app, so one customer can bring you many orders.'],
                ['icon' => 'bolt', 'title' => 'Technology already built', 'text' => 'Customer app, partner app, rider app, shop and restaurant portals, and an admin panel for your city. Nothing to build or buy.'],
                ['icon' => 'map-pin', 'title' => 'Your city to run', 'text' => 'Your franchise login shows and manages your own city: partners, bookings, local prices and your city\'s page content.'],
                ['icon' => 'banknotes', 'title' => 'Clear earnings', 'text' => 'Your share of every completed order in your city is worked out by the system and shown in your Franchise Earnings report.'],
                ['icon' => 'shield', 'title' => 'Safe payments', 'text' => 'Customers pay online through the 1CallFix payment gateway. Refunds and payouts follow written approval rules, never guesswork.'],
                ['icon' => 'users', 'title' => 'Checked local partners', 'text' => 'Every service pro, rider and shop joins through one sign-up, with documents checked and approved before they can take work.'],
            ],
            'support' => [
                ['text' => 'The 1CallFix brand, website and apps for your city'],
                ['text' => 'Your own admin login for your city, with reports'],
                ['text' => 'Partner sign-up with document checks and approval'],
                ['text' => 'Online payments, invoices, refunds and payouts'],
                ['text' => 'Your city\'s own pages on the website, found on Google'],
                ['text' => 'A start code (OTP) on every job, so customers trust your partners'],
                ['text' => 'Training for local service set-up and partner onboarding'],
                ['text' => 'Marketing material: banners, brochures and WhatsApp creatives'],
                ['text' => 'Franchise certificate and area rights'],
                ['text' => 'One month of help hiring your city coordinator'],
            ],
            'invest' => [],
            'plans' => [
                ['name' => 'All-in-One City Franchise', 'was' => '₹20 Lakhs', 'price' => '₹5.5 Lakhs', 'royalty' => '8%', 'validity' => '3 years', 'text' => 'Every business on the app in your city'],
                ['name' => 'Two-Business Franchise', 'was' => '₹10 Lakhs', 'price' => '₹3.5 Lakhs', 'royalty' => '8%', 'validity' => '3 years', 'text' => 'Choose any 2 businesses'],
                ['name' => 'Add a business later', 'was' => '₹2–3 Lakhs', 'price' => '₹1 Lakh each', 'royalty' => '', 'validity' => '', 'text' => 'Add more businesses whenever you are ready'],
            ],
            'payment' => [
                ['title' => 'Booking advance ₹50,000', 'text' => 'Locks your city rights.'],
                ['title' => 'Balance in 20–25 days', 'text' => 'Pay the balance within 20–25 days after signing the Letter of Intent (LOI).'],
                ['title' => 'Refund in 7 days', 'text' => 'Your advance is refundable within 7 days if the franchise is not allotted or approved.'],
            ],
            'areas' => [
                ['type' => 'Area Franchise', 'population' => '3–5 lakh people', 'radius' => '10–15 km radius'],
                ['type' => 'City Franchise', 'population' => '8–10 lakh people', 'radius' => '20–25 km radius'],
            ],
            'compare' => [
                ['label' => 'Businesses you run', 'them' => 'One', 'us' => 'Every business live on the app: services, food, shop, parcel, events, hotels and rentals'],
                ['label' => 'Apps and website', 'them' => 'Often bought or built by you', 'us' => 'Customer, partner, rider, shop and restaurant apps, ready on day one'],
                ['label' => 'Online payments', 'them' => 'Set up on your own', 'us' => 'Through the 1CallFix gateway, with refunds and payouts by written rules'],
                ['label' => 'Your earnings', 'them' => 'Worked out by hand', 'us' => 'Worked out by the system, shown live in your Franchise Earnings report'],
                ['label' => 'Partner checks', 'them' => 'Your own process', 'us' => 'One sign-up with documents checked and approved before any work'],
                ['label' => 'Your city on Google', 'them' => 'Build your own pages', 'us' => 'Your city\'s own pages on the 1CallFix website, ready'],
            ],
            'ideal' => [
                ['text' => 'You live in the city, or know it well'],
                ['text' => 'You can build and lead a small local team'],
                ['text' => 'You are good with people: customers, partners and shop owners'],
                ['text' => 'You are comfortable with apps and a computer'],
                ['text' => 'You want to build a long-term business, not a quick return'],
            ],
            'steps' => [
                ['title' => 'Apply', 'text' => 'Two-minute form: your name, mobile and city.'],
                ['title' => 'Talk to our team', 'text' => 'We call you to understand you and your city, and answer your questions.'],
                ['title' => 'Agree the terms', 'text' => 'You get the city, terms and fees in writing and sign the franchise agreement.'],
                ['title' => 'Set up', 'text' => 'Your admin login, local prices, your first partners and your city\'s pages.'],
                ['title' => 'Launch', 'text' => 'Your city goes live on the 1CallFix app and website.'],
            ],
            'cities' => [],
            'stories' => [],
            'faq' => [
                ['q' => 'Who can apply?', 'a' => 'Anyone who wants to run a local business in their city and can build a small team. Business experience helps; we explain the rest.'],
                ['q' => 'Do I need technical knowledge?', 'a' => 'No. 1CallFix runs the apps, website, payments and updates. You run your city from your admin login.'],
                ['q' => 'How much does a franchise cost?', 'a' => 'See the plans on this page: all businesses, any two, or add one later. You pay a ₹50,000 booking advance to lock your city and the balance within 20–25 days of signing the Letter of Intent. Final fees are written in your franchise agreement.'],
                ['q' => 'Can I run only one or two businesses?', 'a' => 'Yes. Pick only the businesses you want; you pay only for those and can add more later. Your city is switched on for exactly the businesses in your agreement.'],
                ['q' => 'How do I earn?', 'a' => 'You earn a share of every completed booking and order in your city. The exact share is written in your franchise agreement and you can see it live in your Franchise Earnings report.'],
                ['q' => 'Which businesses can I run?', 'a' => 'The businesses live on 1CallFix in your city: home services first, then food delivery, shopping and parcel delivery as each one opens there.'],
                ['q' => 'Is my city available?', 'a' => 'Apply with your city and our team will tell you. One franchise partner runs each city.'],
                ['q' => 'Does applying commit me to anything?', 'a' => 'No. Applying is free. A franchise starts only when both sides sign a written agreement.'],
            ],

            'show_live_stats' => true,
            'show_contact' => true,
        ];
    }

    /** The page content: the Super Admin's saved version over the defaults (a saved key wins, even an empty list). */
    public static function content(): array
    {
        $saved = json_decode((string) Setting::get(self::CONTENT, ''), true);

        return array_replace(self::defaults(), is_array($saved) ? array_intersect_key($saved, self::defaults()) : []);
    }

    public static function get(string $key): mixed
    {
        return self::content()[$key] ?? null;
    }

    public static function enabled(): bool
    {
        return (string) Setting::get(self::ENABLED, '1') !== '0';
    }

    /** The "Franchise owner" card on /partners. Needs the page on too. */
    public static function accepting(): bool
    {
        return self::enabled() && (string) Setting::get(self::ACCEPTING, '1') !== '0';
    }

    /** Module => [homepage door (picture + words), fallback icon, colour when the door has no theme colour]. */
    private const BIZ_LOOK = [
        'service' => ['services', 'wrench', '#2563EB'], 'parcel' => ['parcel', 'map', '#7C3AED'], 'rental' => ['rentals', 'building', '#0D9488'],
        'food' => ['food', 'sparkles', '#E23744'], 'grocery' => [null, 'shopping-bag', '#65A30D'], 'pharmacy' => [null, 'shield', '#0891B2'],
        'commerce' => ['shop', 'shopping-bag', '#16A34A'], 'taxi' => [null, 'map-pin', '#CA8A04'], 'hotel' => ['hotels', 'building', '#0284C7'],
        'events' => ['events', 'star', '#C026D3'],
    ];

    /**
     * The businesses live on the app anywhere (module switches), for the hero tiles, "Every business, one franchise"
     * and the business picker. Each one looks like its homepage "Explore" tile: the same picture (Super Admin's upload
     * or our drawing), name, line and colour. Only what customers can really use today; nothing switched off is shown.
     *
     * @return list<array{slug: string, label: string, title: string, line: string, note: string, image: ?string, icon: string, style: string}>
     */
    public static function liveBusinesses(): array
    {
        try {
            $activation = app(ModuleActivationService::class);
            $content = self::content();

            return collect(Modules::ALL)->filter(fn ($label, $slug) => $activation->isActiveAnywhere($slug))
                ->map(function ($label, $slug) use ($content) {
                    [$door, $icon, $hex] = self::BIZ_LOOK[$slug] ?? [null, 'squares', '#2563EB'];
                    $style = $door ? \App\Support\ModuleTheme::style($door) : '';
                    if ($style === '') {
                        $style = "--mod: {$hex}; --mod-soft: {$hex}1A; --mod-ink: color-mix(in srgb, {$hex} 80%, black);";
                    }

                    return [
                        'slug' => $slug,
                        'label' => $label,
                        'title' => $door ? \App\Support\SiteText::get("home.door.{$door}.title") : $label,
                        'line' => $door ? \App\Support\SiteText::get("home.door.{$door}.line") : '',
                        'note' => (string) ($content['note_'.$slug] ?? ''),
                        'image' => $door ? \App\Support\HomeDoors::imageUrl($door) : null,
                        'icon' => $icon,
                        'style' => $style,
                    ];
                })->values()->all();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /** Where every "Apply" button goes: the one partner form, franchise card picked. */
    public static function applyUrl(): string
    {
        return route('customer.partners', ['role' => self::ROLE]).'#join';
    }

    /**
     * Live numbers, read from the database. A number that is 0 is left out, so a new install never shows "0 partners".
     *
     * @return list<array{value:string, label:string}>
     */
    public static function liveStats(): array
    {
        $out = [];
        try {
            $services = Service::where('is_active', true)->count();
            if ($services > 0) {
                $out[] = ['value' => number_format($services).'+', 'label' => 'services customers can book'];
            }
            $activation = app(ModuleActivationService::class);
            $live = collect([Modules::SERVICE, Modules::FOOD, Modules::COMMERCE, Modules::PARCEL])
                ->filter(fn ($m) => $activation->isActiveAnywhere($m))->count();
            // "1 business" undersells a multi-business app; the number shows once two or more are live.
            if ($live > 1) {
                $out[] = ['value' => (string) $live, 'label' => 'businesses live on one app'];
            }
            // Test logins (@1callfix.test) are never counted.
            $partners = Provider::where('kyc_status', 'approved')
                ->whereDoesntHave('user', fn ($q) => $q->where('email', 'like', '%'.\App\Console\Commands\TestAccountsCommand::DOMAIN))
                ->count();
            if ($partners > 0) {
                $out[] = ['value' => number_format($partners).'+', 'label' => 'approved local partners'];
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return $out;
    }

    /**
     * Checks a full content array from the admin screen. Returns field => message; empty means it can be saved.
     *
     * @return array<string, string>
     */
    public static function validate(array $c): array
    {
        $errors = [];
        $placeholder = fn (string $s) => str_contains($s, '[') || str_contains($s, ']');

        foreach (self::TEXT as $key => [$label, $max]) {
            $v = trim((string) ($c[$key] ?? ''));
            if (mb_strlen($v) > $max) {
                $errors[$key] = "{$label}: at most {$max} characters.";
            } elseif ($placeholder($v)) {
                $errors[$key] = "{$label}: remove the [ ] placeholder before publishing.";
            }
        }
        $share = trim((string) ($c['calc_share'] ?? ''));
        if ($share !== '' && (! is_numeric($share) || (float) $share <= 0 || (float) $share > 100)) {
            $errors['calc_share'] = 'Write a number between 0 and 100, like 10, or leave it empty to hide the calculator.';
        }
        foreach (['fee_all', 'fee_two', 'fee_addon', 'fee_one'] as $fee) {
            $v = trim((string) ($c[$fee] ?? ''));
            if ($v !== '' && ! ctype_digit($v)) {
                $errors[$fee] = 'Numbers only, in rupees, like 350000 (no ₹, commas or spaces).';
            }
        }
        foreach (['hero_title', 'cta_label'] as $required) {
            if (trim((string) ($c[$required] ?? '')) === '' && ! isset($errors[$required])) {
                $errors[$required] = self::TEXT[$required][0].' is needed.';
            }
        }

        foreach (self::LISTS as $key => [$label, $maxItems, $fields]) {
            $rows = $c[$key] ?? [];
            if (! is_array($rows)) {
                $errors[$key] = "{$label}: not a list.";
                continue;
            }
            if (count($rows) > $maxItems) {
                $errors[$key] = "{$label}: at most {$maxItems}.";
                continue;
            }
            foreach (array_values($rows) as $i => $row) {
                foreach ($fields as $field => $max) {
                    $v = trim((string) ($row[$field] ?? ''));
                    if ($field === 'icon') {
                        if (! in_array($v, self::ICONS, true)) {
                            $errors["{$key}.{$i}.{$field}"] = 'Pick an icon from the list.';
                        }
                        continue;
                    }
                    if ($v === '') {
                        if (in_array("{$key}.{$field}", self::OPTIONAL_FIELDS, true)) {
                            continue;
                        }
                        $errors["{$key}.{$i}.{$field}"] = 'Fill this in, or remove the row.';
                    } elseif (mb_strlen($v) > $max) {
                        $errors["{$key}.{$i}.{$field}"] = "At most {$max} characters.";
                    } elseif ($placeholder($v)) {
                        $errors["{$key}.{$i}.{$field}"] = 'Remove the [ ] placeholder before publishing.';
                    }
                }
            }
        }

        return $errors;
    }

    /** Clean a content array down to known keys and trimmed strings, ready to store. */
    public static function clean(array $c): array
    {
        $out = [];
        foreach (self::TEXT as $key => $_) {
            $out[$key] = trim((string) ($c[$key] ?? ''));
        }
        foreach (self::LISTS as $key => [$_l, $_m, $fields]) {
            $out[$key] = array_values(array_map(
                fn ($row) => array_map(fn ($f) => trim((string) ($row[$f] ?? '')), array_combine(array_keys($fields), array_keys($fields))),
                is_array($c[$key] ?? null) ? $c[$key] : [],
            ));
        }
        foreach (self::SWITCHES as $key => $_) {
            $out[$key] = (bool) ($c[$key] ?? false);
        }

        return $out;
    }
}
