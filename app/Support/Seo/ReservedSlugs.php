<?php

namespace App\Support\Seo;

use App\Models\ContentPage;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * F3: words no city/category/service slug may take, because the first URL segment already means something else.
 * Built from the live route collection (so a new route is protected automatically), plus CMS root pages and a
 * short fixed list for things served outside Laravel's routes.
 */
final class ReservedSlugs
{
    private const FIXED = [
        'admin', 'api', 'livewire', 'storage', 'build', 'vendor', 'assets', 'public', 'static', 'images',
        'login', 'logout', 'signup', 'register', 'cart', 'checkout', 'account', 'orders', 'provider', 'providers',
        'sitemap', 'robots', 'favicon', 'manifest', 'up', 'search', 'offers', 'categories', 'services', 'book',
        'choose-city', 'cities', 'city', 'null', 'undefined',
    ];

    /** @return list<string> */
    public static function all(): array
    {
        $words = self::FIXED;

        foreach (Route::getRoutes() as $route) {
            $first = explode('/', trim($route->uri(), '/'))[0] ?? '';
            if ($first === '' || str_starts_with($first, '{')) {
                continue;
            }
            // "sitemap.xml" / "sitemap-{city}.xml" reserve "sitemap" etc.
            $words[] = strtolower(preg_replace('/[.{].*$/', '', $first));
        }

        if (Schema::hasTable('content_pages')) {
            foreach (ContentPage::query()->pluck('slug') as $slug) {
                $words[] = strtolower((string) $slug);
            }
        }

        return array_values(array_unique(array_filter($words)));
    }

    public static function isReserved(string $slug): bool
    {
        return in_array(strtolower($slug), self::all(), true);
    }
}
