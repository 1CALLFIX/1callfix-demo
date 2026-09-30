<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\ContentPage;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Support\Modules;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * REF 1CF-SITEMAP-001 — /sitemap.xml for search engines: the public storefront
 * pages, every active category and service, and every published CMS page.
 * Addresses are built from the host the crawler asked for, so 1callfix.com
 * gets 1callfix.com URLs. Cached for an hour per host.
 */
class SitemapController extends Controller
{
    /** Slugs that already have their own dedicated route (listed once, below). */
    private const OWN_ROUTE_SLUGS = ['privacy', 'terms', 'privacy-policy', 'terms-and-conditions'];

    public function __invoke(): Response
    {
        $xml = Cache::remember('sitemap:'.request()->getHost(), 3600, fn () => $this->build());

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    private function build(): string
    {
        $urls = [];
        $add = function (string $loc, $lastmod = null, string $priority = '0.5') use (&$urls) {
            $urls[$loc] = ['lastmod' => $lastmod?->toAtomString(), 'priority' => $priority];
        };

        $add(route('customer.home'), null, '1.0');
        $add(route('customer.services.index'), null, '0.9');
        $add(route('customer.categories.index'), null, '0.9');
        $add(route('customer.offers'), null, '0.6');
        $add(route('customer.how-it-works'), null, '0.5');
        $add(route('customer.help'), null, '0.5');
        $add(route('customer.partners'), null, '0.5');

        ServiceCategory::query()->where('is_active', true)->where('module', Modules::SERVICE)
            ->get(['id', 'slug', 'updated_at'])
            ->each(fn ($c) => $add(route('customer.categories.show', $c), $c->updated_at, '0.8'));

        Service::query()->where('is_active', true)
            ->whereHas('category', fn ($q) => $q->where('module', Modules::SERVICE)->where('is_active', true))
            ->get()
            ->each(fn ($s) => $add(route('customer.services.show', $s), $s->updated_at, '0.7'));

        $pages = ContentPage::query()->where('is_active', true)->get(['slug', 'updated_at']);
        $hasPrivacy = $pages->contains(fn ($p) => in_array($p->slug, ['privacy', 'privacy-policy'], true));
        $hasTerms = $pages->contains(fn ($p) => in_array($p->slug, ['terms', 'terms-and-conditions'], true));
        if ($hasPrivacy) {
            $add(route('customer.privacy'), null, '0.3');
        }
        if ($hasTerms) {
            $add(route('customer.terms'), null, '0.3');
        }
        $pages->reject(fn ($p) => in_array($p->slug, self::OWN_ROUTE_SLUGS, true))
            ->each(fn ($p) => $add(url('/'.$p->slug), $p->updated_at, '0.6'));

        $out = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($urls as $loc => $meta) {
            $out .= '  <url><loc>'.e($loc).'</loc>'
                .($meta['lastmod'] ? '<lastmod>'.$meta['lastmod'].'</lastmod>' : '')
                .'<priority>'.$meta['priority'].'</priority></url>'."\n";
        }

        return $out.'</urlset>'."\n";
    }
}
