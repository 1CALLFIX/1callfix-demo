<?php

namespace App\Support;

use App\Models\Setting;

/**
 * F2: one canonical origin for every public page, independent of the host the
 * request arrived on (www, api., staging, IP). Super Admin edits it under
 * Settings → Site identity (seo.canonical_base_url, audit-logged).
 */
final class Seo
{
    public const SETTING_KEY = 'seo.canonical_base_url';

    public const DEFAULT_BASE = 'https://1callfix.com';

    public static function canonicalBase(): string
    {
        $base = rtrim(trim((string) Setting::get(self::SETTING_KEY, self::DEFAULT_BASE)), '/');

        return $base === '' ? self::DEFAULT_BASE : $base;
    }

    /** Canonical origin + the current path. The query string is always dropped. */
    public static function canonicalUrl(): string
    {
        $path = request()->getPathInfo();

        return self::canonicalBase().($path === '/' ? '/' : rtrim($path, '/'));
    }
}
