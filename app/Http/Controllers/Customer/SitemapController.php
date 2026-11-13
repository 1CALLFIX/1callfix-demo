<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\ContentPage;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Services\Seo\CityContext;
use App\Services\Seo\CityPages;
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
 *  /sitemap-{city}.xml     that city's hub, services, categories, services, shop and parcel pages that are real
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
        $add($this->abs(route('customer.offers', [], false)), null, '0.6');
        $add($this->abs(route('customer.how-it-works', [], false)), null, '0.5');
        $add($this->abs(route('customer.help', [], false)), null, '0.5');
        $add($this->abs(route('customer.partners', [], false)), null, '0.5');
        $add($this->abs(route('customer.get-app', [], false)), null, '0.6');
        // Owner 2026-11-07: listed only while switched on (a page that 404s never goes into the sitemap).
        if (\App\Livewire\InfoPages\ContactSettings::pageEnabled()) {
            $add($this->abs(route('customer.contact', [], false)), null, '0.4');
        }
        if (\App\Support\InfoPages\FranchisePage::enabled()) {
            $add($this->abs(route('customer.franchise', [], false)), null, '0.6');
        }

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

    /**
     * City URL architecture (2026-11-01): the hub, /services and its categories + services, /shop with its real
     * shops and products, /parcel. Each only when CityPages says the page is real (the page says index on the
     * same rule). Test shops, QA rows, unapproved sellers and inactive products are never listed.
     */
    private function buildCity(City $city): string
    {
        $pages = app(CityPages::class);
        $coverage = app(ProviderCoverage::class);
        $min = ProviderCoverage::minToIndex();
        $counts = $coverage->forCity($city);

        $urls = [];
        $add = function (string $loc, $lastmod, string $priority) use (&$urls) {
            $urls[$loc] = ['lastmod' => $lastmod?->toAtomString(), 'priority' => $priority];
        };

        if ($pages->hubIndexable($city)) {
            $add($this->abs('/'.$city->slug), $city->updated_at, '0.9');
        }

        if ($counts['total'] >= $min) {
            $add($this->abs('/'.$city->slug.'/services'), $city->updated_at, '0.9');
        }

        ServiceCategory::query()->where('is_active', true)->where('module', Modules::SERVICE)
            ->where('name', 'not like', QaRows::LIKE)
            ->get(['id', 'slug', 'updated_at'])
            ->filter(fn ($c) => ($counts['byCategory'][$c->id] ?? 0) >= $min)
            ->each(fn ($c) => $add($this->abs('/'.$city->slug.'/services/'.$c->slug), $c->updated_at, '0.8'));

        Service::query()->where('is_active', true)
            ->where('name', 'not like', QaRows::LIKE)
            ->whereHas('category', fn ($q) => $q->where('module', Modules::SERVICE)->where('is_active', true)
                ->where('name', 'not like', QaRows::LIKE))
            ->get()
            ->filter(fn ($s) => ($counts['byCategory'][$s->category_id] ?? 0) >= $min)
            ->each(fn ($s) => $add($this->abs('/'.$city->slug.'/services/'.$s->slug), $s->updated_at, '0.7'));

        if ($pages->shopIndexable($city)) {
            $add($this->abs('/'.$city->slug.'/shop'), null, '0.8');
            $products = $pages->realProducts($city)->whereNotNull('slug')->get(['id', 'store_id', 'slug', 'updated_at'])->groupBy('store_id');
            $pages->realStores($city)->whereIn('id', $products->keys())->get(['id', 'slug', 'updated_at'])
                ->each(function ($store) use ($add, $city, $products) {
                    $add($this->abs('/'.$city->slug.'/shop/'.$store->slug), $store->updated_at, '0.7');
                    foreach ($products[$store->id] as $product) {
                        $add($this->abs('/'.$city->slug.'/shop/'.$store->slug.'/'.$product->slug), $product->updated_at, '0.6');
                    }
                });
        }

        if ($pages->parcelIndexable($city)) {
            $add($this->abs('/'.$city->slug.'/parcel'), null, '0.7');
        }

        // Food F3: /{city}/food and each real restaurant's menu.
        if ($pages->foodIndexable($city)) {
            $add($this->abs('/'.$city->slug.'/food'), null, '0.8');
            foreach ($pages->realRestaurants($city) as $restaurant) {
                $add($this->abs('/'.$city->slug.'/food/'.$restaurant->slug), $restaurant->updated_at, '0.7');
            }
        }

        // Events E2 (2026-11-13): /{city}/events and each real (never a test) event not over yet, once Events is on.
        if (\App\Support\Seo\CityModules::eventsLiveIn($city)) {
            $events = \App\Models\Event::query()->where('status', 'approved')->where('ends_at', '>', now())
                ->whereHas('franchise', fn ($q) => $q->where('city_id', $city->id))
                ->whereHas('organiser', fn ($q) => $q->where('approval_status', 'approved')->where('is_active', true))
                ->whereDoesntHave('organiser.provider.user', fn ($q) => $q->where('email', 'like', '%'.\App\Console\Commands\TestAccountsCommand::DOMAIN))
                ->get(['slug', 'updated_at']);
            if ($events->isNotEmpty()) {
                $add($this->abs('/'.$city->slug.'/events'), null, '0.8');
                foreach ($events as $event) {
                    $add($this->abs('/'.$city->slug.'/events/'.$event->slug), $event->updated_at, '0.7');
                }
            }
        }

        // Hotels (2026-11-12): /{city}/hotels and each real (never a test) hotel, once Hotels is on in the city.
        if (\App\Support\Seo\CityModules::hotelsLiveIn($city)) {
            $hotels = \App\Models\Accommodation::query()->bookable()->whereHas('franchise', fn ($q) => $q->where('city_id', $city->id))
                ->whereDoesntHave('provider.user', fn ($q) => $q->where('email', 'like', '%'.\App\Console\Commands\TestAccountsCommand::DOMAIN))
                ->get(['slug', 'updated_at']);
            if ($hotels->isNotEmpty()) {
                $add($this->abs('/'.$city->slug.'/hotels'), null, '0.8');
                foreach ($hotels as $hotel) {
                    $add($this->abs('/'.$city->slug.'/hotels/'.$hotel->slug), $hotel->updated_at, '0.7');
                }
            }
        }

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
