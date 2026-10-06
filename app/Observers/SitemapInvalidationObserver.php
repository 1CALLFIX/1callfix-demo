<?php

namespace App\Observers;

use App\Support\Seo;
use Illuminate\Database\Eloquent\Model;

/**
 * Bumps the sitemap version whenever something the sitemap lists (or decides on) is saved or deleted, so a new slug,
 * content page, city or SEO setting shows up at once instead of after the one-hour cache. Cheap: one cache write.
 */
class SitemapInvalidationObserver
{
    public function saved(Model $model): void
    {
        $this->bump($model);
    }

    public function deleted(Model $model): void
    {
        $this->bump($model);
    }

    private function bump(Model $model): void
    {
        // Settings change constantly; only SEO and branding keys affect the sitemap.
        if ($model instanceof \App\Models\Setting && ! preg_match('/^(seo|branding)\./', (string) $model->key)) {
            return;
        }

        Seo::bumpSitemap();
    }
}
