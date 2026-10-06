<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * F2: one canonical origin for every public page, independent of the host the
 * request arrived on (www, api., staging, IP). Super Admin edits it under
 * Settings → Site identity (seo.canonical_base_url, audit-logged).
 */
final class Seo
{
    public const SETTING_KEY = 'seo.canonical_base_url';

    public const DEFAULT_BASE = 'https://1callfix.com';

    private const SITEMAP_VERSION_KEY = 'seo:sitemap_version';

    public static function canonicalBase(): string
    {
        $base = rtrim(trim((string) Setting::get(self::SETTING_KEY, self::DEFAULT_BASE)), '/');

        return $base === '' ? self::DEFAULT_BASE : $base;
    }

    /**
     * Canonical origin + the current path. The query string is dropped (utm_*, gclid, fbclid, sort, search...), with
     * one exception: ?page=N for N >= 2 is kept, so page 2 of a list canonicalises to itself instead of telling
     * search engines that its items live only on page 1.
     */
    public static function canonicalUrl(): string
    {
        $path = request()->getPathInfo();
        $url = self::canonicalBase().($path === '/' ? '/' : rtrim($path, '/'));

        $page = request()->query('page');
        // The home page is not a list, so a stray ?page= there still collapses onto the root URL.
        if ($path !== '/' && is_scalar($page) && ctype_digit((string) $page) && (int) $page >= 2) {
            $url .= '?page='.(int) $page;
        }

        return $url;
    }

    /**
     * An absolute URL on the canonical origin. A relative path is anchored to it; a URL that points at the host
     * the request arrived on (www, api., an IP) is moved onto it; a genuinely external URL is left alone.
     */
    public static function absoluteUrl(string $value): string
    {
        $base = self::canonicalBase();
        $value = trim($value);

        if (! preg_match('#^https?://#i', $value)) {
            return $base.'/'.ltrim($value, '/');
        }

        $origin = request()->getSchemeAndHttpHost();
        if (stripos($value, $origin.'/') === 0 || strcasecmp($value, $origin) === 0) {
            return $base.substr($value, strlen($origin));
        }

        return $value;
    }

    /** Part of every sitemap cache key; bumping it makes the next request rebuild them all. */
    public static function sitemapVersion(): int
    {
        return (int) Cache::get(self::SITEMAP_VERSION_KEY, 1);
    }

    public static function bumpSitemap(): void
    {
        Cache::forever(self::SITEMAP_VERSION_KEY, self::sitemapVersion() + 1);
    }
}
