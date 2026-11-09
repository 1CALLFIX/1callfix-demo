<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Store for ready-made public pages (App\Http\Middleware\CachePublicPage). Pages live PageCache::TTL seconds; a
 * generation number is part of every key, so flush() (any admin save of a setting or catalog item) makes every saved
 * page stale at once without hunting keys. Off in tests unless a test switches it on (PageCache::$enabledInTests).
 */
final class PageCache
{
    public const TTL = 60;

    /** Stands in for the visitor's CSRF token inside a saved page; replaced with the current visitor's on the way out. */
    public const TOKEN_MARK = '__CF_CSRF_TOKEN__';

    private const GENERATION = 'pagecache:generation';

    public static bool $enabledInTests = false;

    public static function enabled(): bool
    {
        if (app()->runningUnitTests()) {
            return self::$enabledInTests;
        }

        return (bool) config('app.page_cache', true);
    }

    public static function key(string $path, string $locale, string $client): string
    {
        return 'pagecache:'.self::generation().':'.sha1($path.'|'.$locale.'|'.$client);
    }

    /** @return array{html: string, session: array<string, mixed>}|null */
    public static function get(string $key): ?array
    {
        try {
            $v = Cache::get($key);

            return is_array($v) && is_string($v['html'] ?? null) && is_array($v['session'] ?? null) ? $v : null;
        } catch (\Throwable) {
            return null; // the cache being down never breaks a page: it is simply built
        }
    }

    /** @param array{html: string, session: array<string, mixed>} $page */
    public static function put(string $key, array $page): void
    {
        try {
            Cache::put($key, $page, self::TTL);
        } catch (\Throwable) {
            // same: never fail a page because it could not be saved
        }
    }

    /** Session keys Laravel itself keeps for every visitor; anything else (except the area's zone) is "personal". */
    private const NEUTRAL_SESSION_KEYS = ['_token', '_previous', '_flash'];

    /**
     * Does this session hold anything about this one visitor? The area's zone alone ("customer" => ["zone_id" => 3])
     * is shared by everyone there; a typed address or pin, guest cart, intended URL or flash message is personal.
     *
     * @param  array<string, mixed>  $session
     */
    public static function isPersonal(array $session): bool
    {
        foreach ($session as $k => $v) {
            if ($k === 'customer' && is_array($v) && array_keys($v) === ['zone_id']) {
                continue;
            }
            if (! in_array($k, self::NEUTRAL_SESSION_KEYS, true)) {
                return true;
            }
        }
        $flash = $session['_flash'] ?? [];

        return ! empty($flash['new']) || ! empty($flash['old']);
    }

    public static function flush(): void
    {
        try {
            Cache::forever(self::GENERATION, self::generation() + 1);
        } catch (\Throwable) {
        }
    }

    private static function generation(): int
    {
        try {
            return (int) Cache::get(self::GENERATION, 1);
        } catch (\Throwable) {
            return 1;
        }
    }
}
