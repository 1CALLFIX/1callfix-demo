<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Services\Customer\CustomerLocationContext;
use App\Services\Seo\CityContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /_hello?path=/nellore/services — called by resources/js/lscache.js on a page the web server handed out
 * without PHP (App\Support\LiteSpeed). Gives the visitor a session and their own security token, and does what
 * building that page would have done for a new visitor: on a city page, point them at that city (CityContext::enter).
 */
class SessionHelloController extends Controller
{
    public function __invoke(Request $request, CityContext $cities): JsonResponse
    {
        $path = trim((string) $request->query('path', ''), '/');
        $first = explode('/', $path)[0] ?? '';

        if ($first !== '' && ! $request->session()->has(CustomerLocationContext::SESSION_KEY)) {
            $city = City::query()->where('slug', $first)->where('is_active', true)->first();
            if ($city && $cities->isLive($city)) {
                $cities->enter($city);
            }
        }

        return response()->json(['token' => $request->session()->token()])
            ->header('Cache-Control', 'no-store, private');
    }
}
