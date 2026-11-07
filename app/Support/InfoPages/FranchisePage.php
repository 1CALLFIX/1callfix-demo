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
        'support' => ['"What you get" lines', 10, ['text' => 140]],
        'invest' => ['Investment figures (label + value), e.g. "Investment" / "₹…"', 6, ['label' => 60, 'value' => 60]],
        'ideal' => ['"Who we look for" lines', 8, ['text' => 140]],
        'steps' => ['Steps', 7, ['title' => 60, 'text' => 220]],
        'cities' => ['Cities open for franchise', 30, ['name' => 60]],
        'stories' => ['Partner stories (only real ones)', 6, ['name' => 60, 'city' => 60, 'quote' => 400]],
        'faq' => ['Questions and answers', 15, ['q' => 160, 'a' => 800]],
    ];

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
            ],
            'invest' => [],
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
                ['q' => 'How much does a franchise cost?', 'a' => 'It depends on the city. We share the investment and fees in writing on your call with our team, before you commit to anything.'],
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
