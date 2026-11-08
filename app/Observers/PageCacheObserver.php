<?php

namespace App\Observers;

use App\Support\PageCache;

/** Any admin save of a setting or catalog item clears the ready-made public pages at once (App\Support\PageCache). */
class PageCacheObserver
{
    public function saved(): void
    {
        PageCache::flush();
    }

    public function deleted(): void
    {
        PageCache::flush();
    }
}
