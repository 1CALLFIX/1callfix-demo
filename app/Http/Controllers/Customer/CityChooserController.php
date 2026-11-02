<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Services\Seo\CityContext;
use App\Services\Slug\SlugManager;
use Illuminate\Http\Request;

/**
 * F3: "which city?" - only needed when more than one city is live and the visitor has not picked one.
 * `?to=services|shop|parcel|services/{slug}` sends the visitor on to that page in the chosen city; other query
 * parameters (UTM!) ride along on every link. With exactly one live city there is nothing to choose: go there.
 */
class CityChooserController extends Controller
{
    public function __invoke(Request $request, CityContext $cities)
    {
        $live = $cities->liveCities();
        abort_if($live->isEmpty(), 404);

        $to = self::destination((string) $request->query('to', ''));
        $carry = $request->except('to');

        $urlFor = function (City $city) use ($to, $carry): string {
            $url = url('/'.$city->slug.($to !== '' ? '/'.$to : ''));

            return $carry === [] ? $url : $url.'?'.http_build_query($carry);
        };

        if ($live->count() === 1) {
            return redirect()->to($urlFor($live->first()), 302);
        }

        return view('customer.city-chooser', [
            'cities' => $live->map(fn (City $c) => ['name' => $c->name, 'url' => $urlFor($c)]),
        ]);
    }

    /**
     * The part after the city: "services", "shop", "parcel" or "services/{slug}". An older link carries a bare catalog
     * slug ("ac-repair"), which now lives under services. Anything else is dropped (the hub), so `to` can never point
     * the visitor off the city's own pages.
     */
    public static function destination(string $to): string
    {
        $parts = array_values(array_filter(explode('/', strtolower(trim($to))), fn ($p) => $p !== ''));
        $parts = array_map(fn ($p) => SlugManager::normalize($p), $parts);
        if ($parts === [] || in_array('', $parts, true)) {
            return '';
        }

        if (in_array($parts[0], \App\Support\Seo\CityModules::LIVE_SEGMENTS, true)) {
            return count($parts) === 1 || ($parts[0] === 'services' && count($parts) === 2) ? implode('/', $parts) : $parts[0];
        }

        return count($parts) === 1 ? 'services/'.$parts[0] : '';
    }
}
