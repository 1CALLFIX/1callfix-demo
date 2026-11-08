<?php

namespace App\Support;

/**
 * The web server's own page cache (owner 2026-11-08: "enable LiteSpeed cache", ahead of franchise and launch ads).
 *
 * OpenLiteSpeed keeps a public page that PHP marks with X-LiteSpeed-Cache-Control and hands it out without starting
 * PHP at all. Only the ready-made pages of App\Http\Middleware\CachePublicPage are ever marked, and only for a
 * plain website visitor with no zone and the default language. Such a copy goes out with no cookies and an EMPTY
 * security token; resources/js/lscache.js fills the visitor's own token back in (cookie cf_csrf, or /_hello).
 *
 * public/.htaccess skips the cache for anyone carrying cf_nc (signed in / something personal), cf_zone, cf_locale or
 * the app's user agent, so they always reach PHP. Any admin save sends X-LiteSpeed-Purge. Switch: LSCACHE in .env.
 */
final class LiteSpeed
{
    public const TTL = 60;

    /** Signed in, or something personal in the session: never served a saved copy by the web server. */
    public const COOKIE_PERSONAL = 'cf_nc';

    /** Mirrors the session's zone, so the web server sends visitors with a zone to PHP (pages differ by zone). */
    public const COOKIE_ZONE = 'cf_zone';

    /** The visitor's own security token, readable by lscache.js to fill into a saved page. */
    public const COOKIE_TOKEN = 'cf_csrf';

    /** Marks a response PHP allows the web server to keep (removed before it leaves). */
    public const MARK = 'X-CF-LiteSpeed';

    /** Set by PageCache::flush(): the current response tells the web server to drop every saved copy. */
    public static bool $purge = false;

    public static function enabled(): bool
    {
        return (bool) config('app.lscache', false);
    }
}
