<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Services\Seo\CityContext;
use App\Services\Slug\SlugManager;
use App\Support\Acquisition\AcquisitionContext;
use App\Support\Seo\PublicUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * F3: the old city-less public URLs - /categories/{slug} (including random-suffix slugs such as
 * "appliance-ac-repair-Bs7r") and /services/{id|slug} - are permanent 301s to /{city}/{slug} with the
 * query string kept, so every ad, bookmark and search result already out there keeps working (UTM survives).
 *
 * The city is the visitor's, else the only live city; with several live cities and no choice made the redirect
 * goes through the chooser, which forwards to the same item.
 */
class LegacyCatalogRedirectController extends Controller
{
    public function category(Request $request, string $slug): RedirectResponse
    {
        $found = SlugManager::resolveCatalog($slug);
        abort_unless($found && $found['model'] instanceof ServiceCategory, 404);

        return $this->to($request, $found['model']->slug);
    }

    public function service(Request $request, string $service): RedirectResponse
    {
        if (ctype_digit($service)) {
            $row = Service::query()->find((int) $service);
            abort_if($row === null, 404);

            // A deactivated duplicate resolves through its redirect row to the kept service.
            $found = $row->is_active ? ['model' => $row] : SlugManager::resolveCatalog((string) $row->slug);
        } else {
            $found = SlugManager::resolveCatalog($service);
        }

        abort_unless($found && $found['model'] instanceof Service, 404);

        return $this->to($request, $found['model']->slug);
    }

    private function to(Request $request, string $slug): RedirectResponse
    {
        $cities = app(CityContext::class);
        $city = $cities->current();

        if ($city) {
            AcquisitionContext::stampCity($request, $city->slug);
        }

        return redirect()->to(PublicUrl::keepingQueryOf(PublicUrl::item($slug, $city), $request), 301);
    }
}
