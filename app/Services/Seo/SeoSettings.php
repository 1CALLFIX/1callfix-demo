<?php

namespace App\Services\Seo;

use App\Models\Module;
use App\Models\Setting;
use App\Models\SocialMediaLink;
use App\Support\Seo;

/**
 * Global, admin-controlled SEO values (Admin → SEO → Search & social). Extends the existing Setting store and
 * SettingsAuditor (every change audit-logged); nothing here is hardcoded except the fallback that keeps a fresh
 * install looking exactly as it did before any value is saved.
 *
 * Resolution order everywhere: page-specific value (city_page_contents, content page, item) → these settings → the
 * built-in fallback. A blank setting simply means "use the fallback", so a typo can never blank a page.
 */
final class SeoSettings
{
    public const TITLE_TEMPLATE = 'seo.site.title_template';
    public const DEFAULT_DESCRIPTION = 'seo.site.default_description';
    public const DEFAULT_IMAGE = 'seo.site.default_image_url';
    public const HOME_TITLE = 'seo.home.title';
    public const HOME_DESCRIPTION = 'seo.home.description';
    public const MODULES = 'seo.modules';
    public const VERIFY_GOOGLE = 'seo.verify.google';
    public const VERIFY_BING = 'seo.verify.bing';
    public const REDIRECT_HOST = 'seo.redirect_to_canonical_host';

    public const BUSINESS_FIELDS = ['legal_name', 'phone', 'email', 'street', 'city', 'region', 'postal_code', 'country'];

    public const FALLBACK_TEMPLATE = '{page} · {site}';

    /** Search engines show roughly this many characters before truncating. */
    public const TITLE_RECOMMENDED = 60;
    public const DESCRIPTION_RECOMMENDED = 160;

    public static function siteName(): string
    {
        return trim((string) Setting::get('branding.platform_name', '1CallFix')) ?: '1CallFix';
    }

    public static function titleTemplate(): string
    {
        $template = trim((string) Setting::get(self::TITLE_TEMPLATE, ''));

        return str_contains($template, '{page}') ? $template : self::FALLBACK_TEMPLATE;
    }

    /** The full <title>. A page with no title of its own is just the site name. */
    public static function renderTitle(?string $page, ?string $template = null): string
    {
        $page = trim((string) $page);
        if ($page === '') {
            return self::siteName();
        }

        return str_replace(['{page}', '{site}'], [$page, self::siteName()], $template ?? self::titleTemplate());
    }

    public static function defaultDescription(): string
    {
        $value = trim((string) Setting::get(self::DEFAULT_DESCRIPTION, ''));

        return $value !== '' ? $value : self::siteName().' — verified local professionals for repairs, installation and maintenance.';
    }

    /** Absolute URL of the default share image, or null (the layout then falls back to the logo). */
    public static function defaultImage(): ?string
    {
        $value = trim((string) Setting::get(self::DEFAULT_IMAGE, ''));

        return $value === '' ? null : Seo::absoluteUrl($value);
    }

    /** Home page title as a COMPLETE title (the template is not applied), or null for the built-in one. */
    public static function homeTitle(): ?string
    {
        $value = trim((string) Setting::get(self::HOME_TITLE, ''));

        return $value === '' ? null : $value;
    }

    public static function homeDescription(): ?string
    {
        $value = trim((string) Setting::get(self::HOME_DESCRIPTION, ''));

        return $value === '' ? null : $value;
    }

    /** @return array<string, array{title: ?string, description: ?string}> keyed by module code */
    public static function modules(): array
    {
        $decoded = json_decode((string) Setting::get(self::MODULES, ''), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array{title: ?string, description: ?string} */
    public static function moduleMeta(string $code): array
    {
        $row = self::modules()[$code] ?? [];

        $clean = fn ($v) => ($v = trim((string) $v)) === '' ? null : $v;

        return ['title' => $clean($row['title'] ?? null), 'description' => $clean($row['description'] ?? null)];
    }

    /** Module codes the admin can write SEO for: every registered module, so a newly activated one appears at once. */
    public static function moduleList(): \Illuminate\Support\Collection
    {
        return Module::query()->orderBy('sort_order')->orderBy('name')->get(['code', 'name', 'is_active', 'is_implemented']);
    }

    /** @return array<string, string> only the filled fields */
    public static function business(): array
    {
        $out = [];
        foreach (self::BUSINESS_FIELDS as $field) {
            $value = trim((string) Setting::get("seo.business.{$field}", ''));
            if ($value !== '') {
                $out[$field] = $value;
            }
        }
        $out['country'] ??= 'IN';

        return $out;
    }

    /** A LocalBusiness block is only emitted when every field a search engine needs is real. */
    public static function businessIsComplete(): bool
    {
        $b = self::business();

        return isset($b['legal_name'], $b['phone'], $b['street'], $b['city'], $b['postal_code']);
    }

    /** @return array{google: ?string, bing: ?string} */
    public static function verification(): array
    {
        $clean = fn (string $key) => ($v = trim((string) Setting::get($key, ''))) === '' ? null : $v;

        return ['google' => $clean(self::VERIFY_GOOGLE), 'bing' => $clean(self::VERIFY_BING)];
    }

    /** Off unless the owner turns it on: redirecting hosts is a cutover decision, never a default. */
    public static function redirectHostEnabled(): bool
    {
        return Setting::get(self::REDIRECT_HOST) === '1';
    }

    /**
     * Structured data for one service page: Service + Offer (real price), AggregateRating only when real reviews
     * exist, and a BreadcrumbList.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function serviceSchema(string $name, ?string $description, ?string $imageUrl, string $url, float $price, ?array $rating): array
    {
        $base = Seo::canonicalBase();
        $service = array_filter([
            '@context' => 'https://schema.org', '@type' => 'Service', 'name' => $name, 'url' => $url,
            'description' => $description,
            'image' => $imageUrl ? Seo::absoluteUrl($imageUrl) : null,
            'provider' => ['@type' => 'Organization', 'name' => self::business()['legal_name'] ?? self::siteName(), 'url' => $base.'/'],
            'areaServed' => self::business()['city'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        if ($price > 0) {
            $service['offers'] = ['@type' => 'Offer', 'price' => number_format($price, 2, '.', ''), 'priceCurrency' => 'INR', 'url' => $url, 'availability' => 'https://schema.org/InStock'];
        }

        // Only ever real review data — never an invented rating.
        if ($rating && ($rating['count'] ?? 0) > 0) {
            $service['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => round((float) $rating['average'], 1), 'reviewCount' => (int) $rating['count']];
        }

        $crumbs = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $base.'/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Services', 'item' => $base.'/services'],
            ['@type' => 'ListItem', 'position' => 3, 'name' => $name, 'item' => $url],
        ]];

        return [$service, $crumbs];
    }

    /**
     * Structured data for the home page: Organization and WebSite always; LocalBusiness only when the business
     * details are complete. Built from the settings above, never from guesses.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function homeSchema(?string $logoUrl): array
    {
        $base = Seo::canonicalBase();
        $b = self::business();
        $name = $b['legal_name'] ?? self::siteName();
        $sameAs = SocialMediaLink::query()->whereNotNull('profile_url')->pluck('profile_url')
            ->filter(fn ($u) => filter_var($u, FILTER_VALIDATE_URL))->values()->all();

        $org = ['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => $name, 'url' => $base.'/'];
        if ($logoUrl) {
            $org['logo'] = Seo::absoluteUrl($logoUrl);
        }
        if ($sameAs !== []) {
            $org['sameAs'] = $sameAs;
        }
        if (isset($b['phone'])) {
            $org['contactPoint'] = array_filter([
                '@type' => 'ContactPoint', 'telephone' => $b['phone'], 'email' => $b['email'] ?? null, 'contactType' => 'customer service',
            ]);
        }

        $schema = [
            $org,
            ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => self::siteName(), 'url' => $base.'/'],
        ];

        if (self::businessIsComplete()) {
            $schema[] = array_filter([
                '@context' => 'https://schema.org', '@type' => 'LocalBusiness', 'name' => $name, 'url' => $base.'/',
                'telephone' => $b['phone'], 'email' => $b['email'] ?? null,
                'image' => $logoUrl ? Seo::absoluteUrl($logoUrl) : null,
                'address' => array_filter([
                    '@type' => 'PostalAddress', 'streetAddress' => $b['street'], 'addressLocality' => $b['city'],
                    'addressRegion' => $b['region'] ?? null, 'postalCode' => $b['postal_code'], 'addressCountry' => $b['country'],
                ]),
            ]);
        }

        return $schema;
    }
}
