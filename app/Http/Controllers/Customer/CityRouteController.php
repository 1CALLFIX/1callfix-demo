<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Livewire\Customer\Catalog\CategoryShow;
use App\Livewire\Customer\Catalog\CityHub;
use App\Livewire\Customer\Catalog\CityParcel;
use App\Livewire\Customer\Catalog\CityShow;
use App\Livewire\Customer\Catalog\ServiceShow;
use App\Models\City;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Services\Seo\CityContext;
use App\Services\Slug\SlugManager;
use App\Support\Acquisition\AcquisitionContext;
use App\Support\Seo\CityModules;
use App\Support\Seo\PublicUrl;
use App\Support\Seo\QaRows;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * F3 + city URL architecture (2026-11-01): the public city pages.
 *
 *   /{city}                    hub: the businesses live in that city (Admin → Modules)
 *   /{city}/services           every service category in the city (was /{city})
 *   /{city}/services/{slug}    a category or a service
 *   /{city}/parcel             parcel delivery in the city, then the (signed-in) send page
 *   /{city}/shop...            the shop (Livewire routes, see routes/web.php)
 *
 * All are greedy wildcards registered LAST (before the CMS fallback), so any slug that is not a city is handed
 * straight to the CMS fallback and behaves exactly as before (CMS root pages, 404s, 405s).
 *
 * Every old or stale form permanently 301s to the current URL with the query string kept (UTM survives):
 *   - an old city slug                    -> the city's current slug
 *   - the older /{city}/{slug} catalog URL -> /{city}/services/{slug}
 *   - an old category/service slug        -> the current slug (also a deactivated duplicate -> the kept item)
 *   - a bare /{slug} that is a catalog item is NOT served (only the city URLs are public)
 */
class CityRouteController extends Controller
{
    public function __construct(private CityContext $cities, private PageController $pages)
    {
    }

    /** GET /{city} */
    public function city(Request $request, string $city): SymfonyResponse
    {
        $found = SlugManager::resolveCity($city);
        if (! $found) {
            return $this->cms($request);
        }

        /** @var City $model */
        $model = $found['model'];
        if ($found['via'] === 'redirect') {
            return $this->moved(url('/'.$model->slug), $request, $model);
        }

        $this->guardLiveCity($model);
        $this->enter($request, $model);

        return $this->invoke(CityHub::class, $request, ['city' => $model]);
    }

    /** GET /{city}/services */
    public function services(Request $request, string $city): SymfonyResponse
    {
        $model = $this->liveCityOrMove($request, $city, 'services');
        if ($model instanceof SymfonyResponse) {
            return $model;
        }

        return $this->invoke(CityShow::class, $request, ['city' => $model]);
    }

    /** GET /{city}/parcel */
    public function parcel(Request $request, string $city): SymfonyResponse
    {
        $model = $this->liveCityOrMove($request, $city, 'parcel');
        if ($model instanceof SymfonyResponse) {
            return $model;
        }
        abort_unless(CityModules::parcelLiveIn($model), 404);

        return $this->invoke(CityParcel::class, $request, ['city' => $model]);
    }

    /** GET /{city}/services/{slug} */
    public function item(Request $request, string $city, string $slug): SymfonyResponse
    {
        $foundCity = SlugManager::resolveCity($city);
        if (! $foundCity) {
            return $this->cms($request);
        }

        /** @var City $cityModel */
        $cityModel = $foundCity['model'];
        $item = SlugManager::resolveCatalog($slug);
        abort_if($item === null, 404);

        $model = $item['model'];

        // Old city slug and/or old item slug: one hop to the canonical URL.
        if ($foundCity['via'] === 'redirect' || $item['via'] === 'redirect') {
            return $this->moved(PublicUrl::item((string) $model->slug, $cityModel), $request, $cityModel);
        }

        $this->guardLiveCity($cityModel);
        $this->guardVisible($model);
        $this->enter($request, $cityModel);

        return $model instanceof ServiceCategory
            ? $this->invoke(CategoryShow::class, $request, ['category' => $model])
            : $this->invoke(ServiceShow::class, $request, ['service' => $model]);
    }

    /**
     * GET /{city}/{slug}: the older catalog URL. A real category/service (or an old slug of one) moves permanently to
     * /{city}/services/{slug}. A reserved business word (/{city}/food before Food has a page) is a plain 404.
     */
    public function legacyItem(Request $request, string $city, string $slug): SymfonyResponse
    {
        $foundCity = SlugManager::resolveCity($city);
        if (! $foundCity) {
            return $this->cms($request);
        }

        /** @var City $cityModel */
        $cityModel = $foundCity['model'];
        abort_if(in_array(strtolower($slug), CityModules::FUTURE_SEGMENTS, true), 404);

        $item = SlugManager::resolveCatalog($slug);
        abort_if($item === null, 404);

        // Never a redirect to a page that does not exist: an inactive item, a hidden city or a QA row stays a plain 404.
        abort_unless((bool) $item['model']->is_active, 404);
        if ($foundCity['via'] === 'live') {
            $this->guardLiveCity($cityModel);
        }
        $this->guardVisible($item['model']);

        return $this->moved(PublicUrl::item((string) $item['model']->slug, $cityModel), $request, $cityModel);
    }

    /** The live city for /{city}/{segment}, or a 301 to the current city slug, or the CMS fallback for a non-city. */
    private function liveCityOrMove(Request $request, string $city, string $segment): City|SymfonyResponse
    {
        $found = SlugManager::resolveCity($city);
        if (! $found) {
            return $this->cms($request);
        }

        /** @var City $model */
        $model = $found['model'];
        if ($found['via'] === 'redirect') {
            return $this->moved(url('/'.$model->slug.'/'.$segment), $request, $model);
        }

        $this->guardLiveCity($model);
        $this->enter($request, $model);

        return $model;
    }

    /** An inactive/franchise-less city has no public page. QA cities are hidden on production. */
    private function guardLiveCity(City $city): void
    {
        abort_unless($this->cities->isLive($city), 404);
    }

    /** QA/demo rows are never public on production. (Active/category checks stay in each page's own mount().) */
    private function guardVisible(object $model): void
    {
        abort_if(QaRows::hiding() && QaRows::isQaName($model->name ?? null), 404);

        if ($model instanceof Service) {
            abort_if(QaRows::hiding() && QaRows::isQaName($model->category?->name), 404);
        }
    }

    private function enter(Request $request, City $city): void
    {
        $request->attributes->set('f3.city', $city);
        $this->cities->enter($city);
        AcquisitionContext::stampCity($request, $city->slug);
    }

    /**
     * Run a Livewire full-page component as if its route had matched, with the already-resolved model as the
     * route parameter (so mount(ServiceCategory $category) receives it unchanged).
     */
    private function invoke(string $component, Request $request, array $parameters): SymfonyResponse
    {
        foreach ($parameters as $name => $value) {
            $request->route()->setParameter($name, $value);
        }

        return app($component)->__invoke();
    }

    private function moved(string $url, Request $request, ?City $city = null): RedirectResponse
    {
        if ($city) {
            AcquisitionContext::stampCity($request, $city->slug);
        }

        // A plain response, not redirect(): after a Livewire page in the same process that helper can be Livewire's.
        return new RedirectResponse(PublicUrl::keepingQueryOf($url, $request), 301);
    }

    private function cms(Request $request): SymfonyResponse
    {
        $view = $this->pages->cms($request);

        return $view instanceof SymfonyResponse ? $view : new Response($view->render());
    }
}
