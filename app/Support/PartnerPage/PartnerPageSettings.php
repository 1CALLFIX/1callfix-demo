<?php

namespace App\Support\PartnerPage;

use App\Models\PartnerBenefit;
use App\Models\Setting;
use App\Support\Modules;

/**
 * REF 1CF-PARTNER-PAGE-001 — every word and list on the public /partners page.
 *
 * Extends the ONE existing settings store (Setting::get / SettingsAuditor::put); there is no second store. Every
 * default lives here, in code, never in a view. A key that is unset reads as its default; a block that has neither a
 * value nor a default (payout timing, support numbers, store links, commission range ...) is simply not rendered.
 * Claims the owner has not confirmed are switches that default OFF and are never hardcoded anywhere.
 *
 * Publishing is guarded: any string containing "[" or "]" (a leftover placeholder) is rejected by validate(), so a
 * half-written template can never go live.
 */
final class PartnerPageSettings
{
    public const PREFIX = 'partner_page.';

    // text keys (value => max length)
    public const TEXT = [
        'hero.title' => ['Hero title', 120],
        'hero.subtitle' => ['Hero subtitle', 300],
        'hero.cta_label' => ['Hero button label', 40],
        'hero.badge' => ['Hero badge', 80],
        'hero.ticks' => ['Hero tick lines (one per line)', 300],
        'roles.lead' => ['Role section intro', 300],
        'join.lead' => ['Join section intro', 300],
        'nellore_band.text' => ['"First city" band text', 240],
        'commission.min' => ['Commission range: lowest (%)', 6],
        'commission.max' => ['Commission range: highest (%)', 6],
        'commission.note' => ['Commission note ({min} and {max} are filled in)', 400],
        'payout_timing' => ['Payout timing note', 240],
        'approval_time' => ['Approval time note', 240],
        'support_phones' => ['Support numbers (one per line)', 300],
        'footer_contact' => ['Footer contact line', 240],
        'needs' => ['What you will need (one per line)', 600],
        'form.consent_text' => ['Form consent wording', 300],
        'form.waitlist_note' => ['Waitlist note above the form', 300],
        'form.done_title' => ['Confirmation title (waitlist roles)', 120],
        'form.done_body' => ['Confirmation text (waitlist roles)', 400],
        'store.android_url' => ['Android app link', 300],
        'store.ios_url' => ['iPhone app link', 300],
        'seo.title' => ['Page title', 120],
        'seo.description' => ['Page description', 320],
    ];

    public const JSON = [
        'steps' => 'Steps',
        'benefits' => 'Benefit tiles',
        'faq' => 'FAQ',
        'role_cards' => 'Role card wording',
        'modules_hidden' => 'Hidden role cards',
        'footer.groups' => 'Footer link groups',
    ];

    /** Switches. true = default ON. */
    public const SWITCHES = [
        'show.nellore_band' => ['Show the "first city" band', true],
        'show.steps' => ['Show the steps', true],
        'show.benefits' => ['Show the benefit tiles', true],
        'show.faq' => ['Show the FAQ', true],
        'claims.joining_free' => ['Claim: "Joining is free."', false],
        'claims.company_accounts' => ['Claim: company accounts are supported', false],
        'claims.whatsapp_updates' => ['Claim: updates on WhatsApp', false],
        'claims.save_finish_later' => ['Claim: save and finish later', false],
    ];

    /** The sentence each claim switch shows when ON. Code, not views; nothing here is shown until the owner switches it on. */
    public const CLAIM_TEXT = [
        'claims.joining_free' => 'Joining is free.',
        'claims.company_accounts' => 'Teams and companies can apply too.',
        'claims.whatsapp_updates' => 'We send you updates on WhatsApp.',
        'claims.save_finish_later' => 'You can save your sign-up and finish it later.',
    ];

    public const LEAD_RETENTION = 'lead_retention_days';

    public const FAQ_TABS = [
        'everyone' => 'Everyone',
        'service' => 'Service professionals',
        'riders' => 'Riders and drivers',
        'shops' => 'Shops and restaurants',
    ];

    public const COLORS = ['blue', 'green', 'amber', 'rose', 'violet', 'teal'];

    /** Role cards, in display order. `commerce` is the registry's code for sellers. */
    public const ROLE_ORDER = ['service', 'parcel', 'food', 'grocery', 'pharmacy', 'taxi', 'hotel', 'rental', 'commerce'];

    public const ROLE_DEFAULTS = [
        'service' => ['Service professional', 'AC, electrical, plumbing, carpentry and appliance repair. Get jobs near you and work on your schedule.'],
        'parcel' => ['Delivery rider', 'Pick up and deliver parcels across your city with your own vehicle. Join the waitlist and be first in line.'],
        'food' => ['Restaurant partner', 'List your kitchen and menu, take orders, and let delivery riders bring them to customers.'],
        'grocery' => ['Grocery store', 'Put your store online and sell groceries to customers nearby.'],
        'pharmacy' => ['Pharmacy', 'List health products and medicines for nearby customers.'],
        'taxi' => ['Cab driver', 'Drive with your own vehicle once rides open in your city.'],
        'hotel' => ['Hotel or stay host', 'List your hotel, homestay or guest house for travellers.'],
        'rental' => ['Property or rental owner', 'List your property or rental for customers who want to rent.'],
        'commerce' => ['Seller or shop', 'Sell your products to customers near you.'],
    ];

    /** Module name shown on each role card. */
    public const ROLE_MODULE_NAMES = [
        'service' => 'Home services', 'parcel' => 'Parcel delivery', 'food' => 'Food delivery', 'grocery' => 'Grocery',
        'pharmacy' => 'Pharmacy', 'taxi' => 'Taxi', 'hotel' => 'Hotel booking', 'rental' => 'Rentals', 'commerce' => 'Marketplace',
    ];

    private const MAX_JSON_BYTES = 20000;

    // ------------------------------------------------------------------ reading

    public static function key(string $short): string
    {
        return self::PREFIX.$short;
    }

    public const CACHE_KEY = 'partner_page:all';

    /**
     * Every global partner_page.* setting in ONE cached array. Reading each key through Setting::get() would
     * re-query the database on every request for each key that is unset (the store caches "no row" as a miss), and
     * the footer reads these keys on every customer page. One cached read means a warm request adds no queries.
     * Cleared by forgetCache() (admin save) and whenever a partner_page.* Setting row is saved (see AppServiceProvider).
     *
     * @return array<string, string> full key => value
     */
    private static function all(): array
    {
        // Memoised for the current request only: a request reads the cached array once, not once per key. The memo
        // is tied to the request instance (a new request, or a test's next call, starts clean) and is not used by
        // long-running console processes such as queue workers, where one request instance lives for hours.
        $request = app()->bound('request') ? app('request') : null;
        $memoise = $request !== null && (! app()->runningInConsole() || app()->runningUnitTests());
        if ($memoise && self::$memoRequest?->get() === $request && self::$memo !== null) {
            return self::$memo;
        }

        $all = cache()->rememberForever(self::CACHE_KEY, fn () => Setting::query()
            ->where('scope_type', 'global')->whereNull('scope_id')->where('key', 'like', self::PREFIX.'%')
            ->pluck('value', 'key')->map(fn ($v) => (string) $v)->all());

        if ($memoise) {
            self::$memo = $all;
            self::$memoRequest = \WeakReference::create($request);
        }

        return $all;
    }

    /** @var array<string, string>|null */
    private static ?array $memo = null;

    private static ?\WeakReference $memoRequest = null;

    private static function raw(string $short): ?string
    {
        $v = self::all()[self::key($short)] ?? null;

        return ($v === null || trim((string) $v) === '') ? null : trim((string) $v);
    }

    /** Stored text, else the in-code default, else null (block hidden). */
    public static function text(string $short): ?string
    {
        return self::raw($short) ?? self::defaults()[$short] ?? null;
    }

    public static function on(string $short): bool
    {
        $raw = self::raw($short);

        return $raw === null ? (self::SWITCHES[$short][1] ?? false) : $raw === '1';
    }

    /** @return list<string> */
    public static function lines(string $short): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) self::text($short)) ?: [])));
    }

    /** @return list<array{title:string, body:string}> */
    public static function steps(): array
    {
        return self::decoded('steps', self::defaultSteps());
    }

    /** @return list<array{icon:string, color:string, title:string, body:string}> */
    public static function benefits(): array
    {
        // The public page no longer reads the Website / CMS partner_benefits table; tiles are edited under Partner page.
        return self::decoded('benefits', self::defaultBenefits());
    }

    /** @return list<array{tab:string, q:string, a:string}> */
    public static function faq(): array
    {
        return self::decoded('faq', self::defaultFaq());
    }

    /** @return list<string> module codes the admin hid */
    public static function hiddenModules(): array
    {
        $v = self::decoded('modules_hidden', []);

        return array_values(array_filter($v, 'is_string'));
    }

    /** @return array<string, array{label:string, blurb:string}> */
    public static function roleWording(): array
    {
        $own = self::decoded('role_cards', []);
        $out = [];
        foreach (self::ROLE_DEFAULTS as $code => [$label, $blurb]) {
            $out[$code] = [
                'label' => trim((string) ($own[$code]['label'] ?? '')) ?: $label,
                'blurb' => trim((string) ($own[$code]['blurb'] ?? '')) ?: $blurb,
            ];
        }

        return $out;
    }

    /** The commission sentence, only when both ends of the range are configured. */
    public static function commissionLine(): ?string
    {
        $min = self::text('commission.min');
        $max = self::text('commission.max');
        $note = self::text('commission.note');
        if ($min === null || $max === null || $note === null) {
            return null;
        }

        return str_replace(['{min}', '{max}'], [$min, $max], $note);
    }

    public static function retentionDays(): ?int
    {
        $v = self::raw(self::LEAD_RETENTION);

        return $v !== null && ctype_digit($v) && (int) $v > 0 ? (int) $v : null;
    }

    private static function decoded(string $short, array $default): array
    {
        $raw = self::raw($short);
        if ($raw === null) {
            return $default;
        }
        $v = json_decode($raw, true);

        return is_array($v) ? $v : $default;
    }

    // ------------------------------------------------------------------ writing guard

    /**
     * @param  array<string, mixed>  $values  short key => submitted value (strings for text/switch/json)
     * @return array<string, string> short key => error message; empty when publishable
     */
    public static function validate(array $values): array
    {
        $errors = [];

        foreach (self::TEXT as $short => [$label, $max]) {
            $v = trim((string) ($values[$short] ?? ''));
            if (mb_strlen($v) > $max) {
                $errors[$short] = "{$label} is too long (max {$max}).";
            }
        }

        foreach (['commission.min', 'commission.max'] as $k) {
            $v = trim((string) ($values[$k] ?? ''));
            if ($v !== '' && (! is_numeric($v) || (float) $v < 0 || (float) $v > 100)) {
                $errors[$k] = 'Enter a number between 0 and 100.';
            }
        }
        $min = trim((string) ($values['commission.min'] ?? ''));
        $max = trim((string) ($values['commission.max'] ?? ''));
        if (! isset($errors['commission.min'], $errors['commission.max'])) {
            if (($min === '') !== ($max === '')) {
                $errors['commission.max'] = 'Fill both ends of the commission range, or neither.';
            } elseif ($min !== '' && (float) $min > (float) $max) {
                $errors['commission.max'] = 'The highest commission cannot be below the lowest.';
            }
        }

        foreach (['store.android_url', 'store.ios_url'] as $k) {
            $v = trim((string) ($values[$k] ?? ''));
            if ($v !== '' && ! preg_match('#^https://[^\s]+$#i', $v)) {
                $errors[$k] = 'Use a full https:// link.';
            }
        }

        $days = trim((string) ($values[self::LEAD_RETENTION] ?? ''));
        if ($days !== '' && (! ctype_digit($days) || (int) $days < 30 || (int) $days > 3650)) {
            $errors[self::LEAD_RETENTION] = 'Enter 30 to 3650 days, or leave blank to keep leads.';
        }

        foreach (array_keys(self::JSON) as $short) {
            $v = trim((string) ($values[$short] ?? ''));
            if ($v === '') {
                continue;
            }
            if (strlen($v) > self::MAX_JSON_BYTES) {
                $errors[$short] = 'Too large (max '.self::MAX_JSON_BYTES.' bytes).';
                continue;
            }
            $data = json_decode($v, true);
            if (! is_array($data)) {
                $errors[$short] = 'Not valid JSON.';
                continue;
            }
            if ($msg = self::checkShape($short, $data)) {
                $errors[$short] = $msg;
            }
        }

        // Publish guard: no leftover [placeholder] anywhere, including inside JSON.
        foreach ($values as $short => $v) {
            if (isset($errors[$short]) || ! is_string($v)) {
                continue;
            }
            if (self::containsBracket($v)) {
                $errors[$short] = 'Contains [ or ]. Replace the placeholder before publishing.';
            }
        }

        return $errors;
    }

    private static function containsBracket(string $v): bool
    {
        // Brackets that are JSON array syntax are fine; brackets inside any STRING value are not.
        $data = json_decode($v, true);
        if (is_array($data)) {
            $found = false;
            array_walk_recursive($data, function ($x) use (&$found) {
                if (is_string($x) && (str_contains($x, '[') || str_contains($x, ']'))) {
                    $found = true;
                }
            });

            return $found;
        }

        return str_contains($v, '[') || str_contains($v, ']');
    }

    private static function checkShape(string $short, array $data): ?string
    {
        $str = fn ($x, int $max) => is_string($x) && trim($x) !== '' && mb_strlen($x) <= $max;

        switch ($short) {
            case 'steps':
                if (! array_is_list($data) || count($data) < 1 || count($data) > 8) {
                    return 'Provide 1 to 8 steps.';
                }
                foreach ($data as $s) {
                    if (! is_array($s) || ! $str($s['title'] ?? null, 80) || ! $str($s['body'] ?? null, 240)) {
                        return 'Each step needs a title (max 80) and body (max 240).';
                    }
                }

                return null;
            case 'benefits':
                if (! array_is_list($data) || count($data) < 1 || count($data) > 12) {
                    return 'Provide 1 to 12 tiles.';
                }
                foreach ($data as $b) {
                    if (! is_array($b) || ! $str($b['title'] ?? null, 80) || ! $str($b['body'] ?? null, 240)
                        || ! array_key_exists($b['icon'] ?? '', PartnerBenefit::ICONS) || ! in_array($b['color'] ?? '', self::COLORS, true)) {
                        return 'Each tile needs title, body, an icon ('.implode(', ', array_keys(PartnerBenefit::ICONS)).') and a colour ('.implode(', ', self::COLORS).').';
                    }
                }

                return null;
            case 'faq':
                if (! array_is_list($data) || count($data) < 1 || count($data) > 40) {
                    return 'Provide 1 to 40 questions.';
                }
                foreach ($data as $f) {
                    if (! is_array($f) || ! array_key_exists($f['tab'] ?? '', self::FAQ_TABS) || ! $str($f['q'] ?? null, 200) || ! $str($f['a'] ?? null, 1000)) {
                        return 'Each question needs tab ('.implode(', ', array_keys(self::FAQ_TABS)).'), q (max 200) and a (max 1000).';
                    }
                }

                return null;
            case 'role_cards':
                foreach ($data as $code => $w) {
                    if (! in_array($code, self::ROLE_ORDER, true) || ! is_array($w)
                        || (isset($w['label']) && ! $str($w['label'], 60)) || (isset($w['blurb']) && ! $str($w['blurb'], 160))) {
                        return 'Keys must be role codes ('.implode(', ', self::ROLE_ORDER).') with label (max 60) and blurb (max 160).';
                    }
                }

                return null;
            case 'footer.groups':
                if (! array_is_list($data) || count($data) < 1 || count($data) > 6) {
                    return 'Provide 1 to 6 groups.';
                }
                foreach ($data as $g) {
                    if (! is_array($g) || ! $str($g['title'] ?? null, 40) || ! is_array($g['links'] ?? null) || ! array_is_list($g['links'])
                        || count($g['links']) < 1 || count($g['links']) > 12) {
                        return 'Each group needs a title (max 40) and 1 to 12 links.';
                    }
                    foreach ($g['links'] as $l) {
                        if (! is_array($l) || ! $str($l['label'] ?? null, 40) || ! self::safeHref($l['href'] ?? null)) {
                            return 'Each link needs a label (max 40) and an address starting with /, http://, https://, mailto: or tel:.';
                        }
                    }
                }

                return null;
            case 'modules_hidden':
                if (! array_is_list($data) || array_diff($data, Modules::slugs()) !== []) {
                    return 'Provide a list of module codes.';
                }

                return null;
        }

        return null;
    }

    /**
     * A link address is safe only if it is a root-relative path, or an http, https, mailto or tel address. Anything
     * else is refused: javascript:, data:, vbscript:, protocol-relative //host, a backslash (browsers read "/\host"
     * as "//host"), leading whitespace, and any whitespace or control character anywhere (so "java<TAB>script:" and
     * "java<LF>script:" can never reach a browser that strips them).
     */
    public static function safeHref(mixed $href): bool
    {
        if (! is_string($href) || $href === '' || strlen($href) > 500) {
            return false;
        }
        if (preg_match('/[\x00-\x20\x7F\\\\]/', $href) === 1) {
            return false;
        }

        return preg_match('#^(/(?!/)[^\s]*|https?://[^\s/][^\s]*|mailto:[^\s]+|tel:[+0-9()-]+)$#i', $href) === 1;
    }

    /** Every settings key this page owns (for cache clearing and the admin screen). */
    public static function allShortKeys(): array
    {
        return array_merge(array_keys(self::TEXT), array_keys(self::JSON), array_keys(self::SWITCHES), [self::LEAD_RETENTION]);
    }

    public static function forgetCache(): void
    {
        self::$memo = null;
        self::$memoRequest = null;
        cache()->forget(self::CACHE_KEY);
        foreach (self::allShortKeys() as $short) {
            cache()->forget('setting:global::'.self::key($short));
        }
    }

    // ------------------------------------------------------------------ defaults (code, never views)

    /** @return array<string, string> */
    public static function defaults(): array
    {
        return [
            'hero.title' => "Your skills.\nOur customers.\nYour schedule.",
            'hero.subtitle' => 'One page for every role. Pick yours, apply with your mobile number, and get every question answered before you start.',
            'hero.cta_label' => 'Start your application',
            'hero.badge' => 'Now onboarding partners in Nellore',
            'hero.ticks' => "Apply with your mobile number\nGo online or offline whenever you choose\nAccept or reject every job offer",
            'roles.lead' => 'Every 1CallFix module has its own partner role. A role goes live when its module opens in your city. Until then, join the waitlist and we will tell you the day it opens.',
            'join.lead' => 'Tell us who you are and which role you want. We save your details, then you continue in the 1CallFix app, where your mobile number is verified with a one-time code.',
            'nellore_band.text' => 'Starting in Nellore. More cities open as local franchises launch.',
            'commission.note' => 'Standard commission is {min} to {max} percent of the job value. Your exact rate is agreed with 1CallFix or your local franchise and shown in your partner terms.',
            'needs' => "A mobile number that can receive a one-time code\nThe ID and proof documents requested during sign-up\nA bank account or UPI to receive payouts",
            'form.consent_text' => 'I agree to be contacted by 1CallFix about partnering, and to my details being stored for this purpose.',
            'form.done_title' => 'You are on the waitlist',
            'form.waitlist_note' => 'We have saved your details. We will contact you on your mobile number when this role opens in your city.',
            'form.done_body' => 'We have saved your details. We will contact you on your mobile number when this role opens in your city.',
        ];
    }

    public static function defaultSteps(): array
    {
        return [
            ['title' => 'Apply', 'body' => 'Choose your role and enter your name and mobile number on this page.'],
            ['title' => 'Verify', 'body' => 'In the app sign-up, confirm your mobile with a one-time code, then upload the ID and proof documents we ask for. Our team checks every application.'],
            ['title' => 'Get approved', 'body' => 'Our team reviews your documents. Your KYC status shows on your partner dashboard when you sign in.'],
            ['title' => 'Go online', 'body' => 'Open the 1CallFix Partner app, go online, and start receiving job offers near you.'],
        ];
    }

    public static function defaultBenefits(): array
    {
        return [
            ['icon' => 'clipboard', 'color' => 'amber', 'title' => 'Job offers near you', 'body' => 'Jobs reach you by area and skill, so your offers fit the work you actually do.'],
            ['icon' => 'clock', 'color' => 'blue', 'title' => 'You choose your hours', 'body' => 'Go online when you want work and offline when you do not. Accept or reject each offer.'],
            ['icon' => 'shield', 'color' => 'green', 'title' => 'Safe start and finish', 'body' => 'The customer shares a one-time code to start the job and another to finish it.'],
            ['icon' => 'wallet', 'color' => 'rose', 'title' => 'Earnings you can see', 'body' => 'Track each completed job and your wallet in the app.'],
            ['icon' => 'banknotes', 'color' => 'violet', 'title' => 'Payouts to your bank or UPI', 'body' => 'Request a payout to your verified bank or UPI account.'],
        ];
    }

    public static function defaultFaq(): array
    {
        return [
            ['tab' => 'everyone', 'q' => 'What documents do I need?', 'a' => 'The app sign-up asks for the ID and proof documents set up for providers, and our team checks them before you can take jobs. The exact list appears during sign-up.'],
            ['tab' => 'everyone', 'q' => 'How long does approval take?', 'a' => 'Our team reviews each application. Your KYC status shows on your partner dashboard when you sign in.'],
            ['tab' => 'everyone', 'q' => 'Can I choose my own hours?', 'a' => 'Yes. You go online when you want to receive jobs and offline when you do not. You can accept or reject any offer.'],
            ['tab' => 'everyone', 'q' => 'How do I get paid?', 'a' => 'Your earnings for each completed job and your wallet balance show in the app. Request a payout to your verified bank or UPI account.'],
            ['tab' => 'everyone', 'q' => 'Which cities can I work in?', 'a' => '1CallFix is starting in Nellore. More cities open as local franchises launch. Choose your city in the form, or join the waitlist for yours.'],
            ['tab' => 'service', 'q' => 'How does a job start and finish?', 'a' => 'The customer shares a one-time code to start the job and another to finish it. You enter each in the app.'],
            ['tab' => 'riders', 'q' => 'What do riders and drivers need?', 'a' => 'These roles are not open yet. Apply on this page to join the waitlist; we will tell you what is needed when yours opens.'],
            ['tab' => 'riders', 'q' => 'When will rider and driver roles open?', 'a' => 'Each module opens city by city. Join the waitlist and we will message you when yours opens.'],
            ['tab' => 'shops', 'q' => 'What do shops and restaurants need?', 'a' => 'These roles are not open yet. Apply on this page to join the waitlist; we will tell you what is needed when yours opens.'],
        ];
    }
}
