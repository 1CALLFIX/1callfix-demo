<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\ContentPage;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Services\Seo\CityContext;
use App\Services\Seo\ProviderCoverage;
use App\Support\Modules;
use App\Support\Seo;
use App\Support\Seo\QaRows;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * REF 1CF-SITEMAP-001 / F3 - sitemaps for search engines.
 *
 *  /sitemap.xml            index: the static sitemap + one sitemap per LIVE city
 *  /sitemap-static.xml     storefront pages + published CMS pages (city-independent)
 *  /sitemap-{city}.xml     that city's page, and its categories and services that are real
 *
 * Every address is built on seo.canonical_base_url (never the request host). Only REAL pages are listed:
 * the city must be live (active + an active franchise), and a category/service needs at least
 * `seo.min_providers_to_index` approved providers with that category in the city. QA/demo rows
 * ("[QA] ..." names) are left out in every environment. Cached for an hour.
 */
class SitemapController extends Controller
{
    /** Slugs that already have their own dedicated route (listed once, in the static map). */
    private const OWN_ROUTE_SLUGS = ['privacy', 'terms', 'privacy-policy', 'terms-and-conditions'];

    public function index(): Response
    {
        return $this->xml('index', fn () => $this->buildIndex());
    }

    public function static(): Response
    {
        return $this->xml('static', fn () => $this->buildStatic());
    }

    public function city(string $city): Response
    {
        $model = City::query()->where('slug', $city)->first();
        abort_unless($model && app(CityContext::class)->isLive($model) && ! QaRows::isQaName($model->name), 404);

        return $this->xml('city:'.$model->id, fn () => $this->buildCity($model));
    }

    private function xml(string $key, \Closure $build): Response
    {
        $xml = Cache::remember('sitemap:v'.Seo::sitemapVersion().':'.md5(Seo::canonicalBase()).':'.$key, 3600, $build);

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /** Absolute canonical-base URL for a path. */
    private function abs(string $path): string
    {
        return Seo::canonicalBase().'/'.ltrim($path, '/');
    }

    private function buildIndex(): string
    {
        $maps = [$this->abs('sitemap-static.xml')];
        foreach (app(CityContext::class)->liveCities() as $city) {
            if (! QaRows::isQaName($city->name)) {
                $maps[] = $this->abs('sitemap-'.$city->slug.'.xml');
            }
        }

        $out = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($maps as $loc) {
            $out .= '  <sitemap><loc>'.e($loc).'</loc></sitemap>'."\n";
        }

        return $out.'</sitemapindex>'."\n";
    }

    private function buildStatic(): string
    {
        $urls = [];
        $add = function (string $loc, $lastmod = null, string $priority = '0.5') use (&$urls) {
            $urls[$loc] = ['lastmod' => $lastmod?->toAtomString(), 'priority' => $priority];
        };

        $add($this->abs('/'), null, '1.0');
        $add($this->abs(route('customer.services.index', [], false)), null, '0.9');
        $add($this->abs(route('customer.categories.index', [], false)), null, '0.9');
        $add($this->abs(route('customer.offers', [], false)), null, '0.6');
        $add($this->abs(route('customer.how-it-works', [], false)), null, '0.5');
        $add($this->abs(route('customer.help', [], false)), null, '0.5');
        $add($this->abs(route('customer.partners', [], false)), null, '0.5');

        $pages = ContentPage::query()->where('is_active', true)->get(['slug', 'updated_at']);
        $hasPrivacy = $pages->contains(fn ($p) => in_array($p->slug, ['privacy', 'privacy-policy'], true));
        $hasTerms = $pages->contains(fn ($p) => in_array($p->slug, ['terms', 'terms-and-conditions'], true));
        if ($hasPrivacy) {
            $add($this->abs(route('customer.privacy', [], false)), null, '0.3');
        }
        if ($hasTerms) {
            $add($this->abs(route('customer.terms', [], false)), null, '0.3');
        }
        $pages->reject(fn ($p) => in_array($p->slug, self::OWN_ROUTE_SLUGS, true))
            ->each(fn ($p) => $add($this->abs('/'.$p->slug), $p->updated_at, '0.6'));

        return $this->urlset($urls);
    }

    private function buildCity(City $city): string
    {
        $coverage = app(ProviderCoverage::class);
        $min = ProviderCoverage::minToIndex();
        $counts = $coverage->forCity($city);

        $urls = [];
        $add = function (string $loc, $lastmod, string $priority) use (&$urls) {
            $urls[$loc] = ['lastmod' => $lastmod?->toAtomString(), 'priority' => $priority];
        };

        if ($counts['total'] >= $min) {
            $add($this->abs('/'.$city->slug), $city->updated_at, '0.9');
        }

        ServiceCategory::query()->where('is_active', true)->where('module', Modules::SERVICE)
            ->where('name', 'not like', QaRows::LIKE)
            ->get(['id', 'slug', 'updated_at'])
            ->filter(fn ($c) => ($counts['byCategory'][$c->id] ?? 0) >= $min)
            ->each(fn ($c) => $add($this->abs('/'.$city->slug.'/'.$c->slug), $c->updated_at, '0.8'));

        Service::query()->where('is_active', true)
            ->where('name', 'not like', QaRows::LIKE)
            ->whereHas('category', fn ($q) => $q->where('module', Modules::SERVICE)->where('is_active', true)
                ->where('name', 'not like', QaRows::LIKE))
            ->get()
            ->filter(fn ($s) => ($counts['byCategory'][$s->category_id] ?? 0) >= $min)
            ->each(fn ($s) => $add($this->abs('/'.$city->slug.'/'.$s->slug), $s->updated_at, '0.7'));

        return $this->urlset($urls);
    }

    /** @param array<string, array{lastmod: ?string, priority: string}> $urls */
    private function urlset(array $urls): string
    {
        $out = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($urls as $loc => $meta) {
            $out .= '  <url><loc>'.e($loc).'</loc>'
                .($meta['lastmod'] ? '<lastmod>'.$meta['lastmod'].'</lastmod>' : '')
                .'<priority>'.$meta['priority'].'</priority></url>'."\n";
        }

        return $out.'</urlset>'."\n";
    }
}
