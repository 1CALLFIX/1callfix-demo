<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Livewire\Customer\Catalog\CategoryShow;
use App\Livewire\Customer\Catalog\CityShow;
use App\Livewire\Customer\Catalog\ServiceShow;
use App\Models\City;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Services\Seo\CityContext;
use App\Services\Slug\SlugManager;
use App\Support\Acquisition\AcquisitionContext;
use App\Support\Seo\QaRows;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * F3: the two public catalog URLs, /{city} and /{city}/{slug}.
 *
 * Both are greedy wildcards registered LAST (before the CMS fallback), so any slug that is not a city / catalog item
 * is handed straight to the CMS fallback and behaves exactly as before (CMS root pages, 404s, 405s).
 *
 * Every old or stale form permanently 301s to the current URL with the query string kept (UTM survives):
 *   - an old city slug             -> the city's current slug
 *   - an old category/service slug -> the current /{city}/{slug}   (also a deactivated duplicate -> the kept item)
 *   - a bare /{slug} that is a catalog item is NOT served (only /{city}/{slug} is public)
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

        return $this->invoke(CityShow::class, $request, ['city' => $model]);
    }

    /** GET /{city}/{slug} */
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
            return $this->moved(url('/'.$cityModel->slug.'/'.$model->slug), $request, $cityModel);
        }

        $this->guardLiveCity($cityModel);
        $this->guardVisible($model);
        $this->enter($request, $cityModel);

        return $model instanceof ServiceCategory
            ? $this->invoke(CategoryShow::class, $request, ['category' => $model])
            : $this->invoke(ServiceShow::class, $request, ['service' => $model]);
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

        return redirect()->to(\App\Support\Seo\PublicUrl::keepingQueryOf($url, $request), 301);
    }

    private function cms(Request $request): SymfonyResponse
    {
        $view = $this->pages->cms($request);

        return $view instanceof SymfonyResponse ? $view : new Response($view->render());
    }
}
