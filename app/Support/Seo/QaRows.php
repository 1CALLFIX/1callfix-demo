<?php

namespace App\Support\Seo;

/**
 * How QA/demo catalog rows are identified: App\Services\Qa\QaSeeder prefixes the NAME of every row it creates
 * (cities, franchises, zones, categories, subcategories, services) with "[QA] ", and tracks them in its manifest.
 * There is no marker column. This is the one place that knows the convention.
 *
 *  - Sitemaps and indexing: QA rows are ALWAYS excluded / noindex, in every environment.
 *  - Public pages: hidden (404) when hiding() is true, which defaults to production.
 */
final class QaRows
{
    public const PREFIX = '[QA]';

    /** SQL LIKE form of the prefix, with the bracket escaped for MySQL/SQLite alike (brackets are literal in LIKE). */
    public const LIKE = '[QA]%';

    public static function isQaName(?string $name): bool
    {
        return $name !== null && str_starts_with(ltrim($name), self::PREFIX);
    }

    public static function hiding(): bool
    {
        return (bool) (config('seo.hide_qa_rows') ?? app()->environment('production'));
    }
}
