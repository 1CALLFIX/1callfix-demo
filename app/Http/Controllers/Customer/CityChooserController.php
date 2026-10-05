<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Services\Seo\CityContext;
use App\Services\Slug\SlugManager;
use Illuminate\Http\Request;

/**
 * F3: "which city?" - only needed when more than one city is live and the visitor has not picked one.
 * `?to={slug}` (a catalog slug) sends the visitor on to that item in the chosen city; other query
 * parameters (UTM!) ride along on every link. With exactly one live city there is nothing to choose: go there.
 */
class CityChooserController extends Controller
{
    public function __invoke(Request $request, CityContext $cities)
    {
        $live = $cities->liveCities();
        abort_if($live->isEmpty(), 404);

        $to = SlugManager::normalize((string) $request->query('to', ''));
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
}
