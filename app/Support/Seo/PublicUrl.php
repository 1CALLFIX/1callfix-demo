<?php

namespace App\Support\Seo;

use App\Models\City;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Services\Seo\CityContext;

/**
 * F3: the ONE builder of public catalog URLs. Views, components, emails and the sitemap call this;
 * nothing hand-builds "/categories/..." or "/{city}/..." strings (see CLAUDE.md thumb rule on public links).
 *
 * Shape (city URL architecture, 2026-11-01): /{city} (hub), /{city}/services, /{city}/services/{slug},
 * /{city}/shop, /{city}/parcel. The older /{city}/{slug} permanently 301s to /{city}/services/{slug}.
 * Absolute URLs, like route().
 * When no city can be inferred (several live cities, visitor has not picked one) a link goes through the
 * chooser, which forwards to the same item once a city is picked.
 */
final class PublicUrl
{
    /**
     * Append the request's query string to `$url` exactly as it arrived (same order, same encoding), so a
     * redirect never reshuffles or re-encodes UTM/click-id parameters.
     */
    public static function keepingQueryOf(string $url, \Illuminate\Http\Request $request): string
    {
        $query = (string) $request->server->get('QUERY_STRING', $request->getQueryString() ?? '');

        return $query === '' ? $url : $url.(str_contains($url, '?') ? '&' : '?').$query;
    }

    /** The city hub: /{city}, the businesses live there. */
    public static function city(City $city): string
    {
        return url('/'.$city->slug);
    }

    /** /{city}/services, /{city}/shop, /{city}/parcel for an explicit city, else the inferred one, else the chooser. */
    public static function section(string $segment, ?City $city = null): string
    {
        $city ??= app(CityContext::class)->current();

        return $city ? url('/'.$city->slug.'/'.$segment) : route('customer.city.choose', ['to' => $segment]);
    }

    public static function services(?City $city = null): string
    {
        return self::section('services', $city);
    }

    /** @param array<string, scalar> $query extra query parameters (e.g. ['sub' => 3]) */
    public static function category(ServiceCategory $category, ?City $city = null, array $query = []): string
    {
        return self::item((string) $category->slug, $city, $query);
    }

    /** @param array<string, scalar> $query */
    public static function service(Service $service, ?City $city = null, array $query = []): string
    {
        return self::item((string) $service->slug, $city, $query);
    }

    /**
     * `/{city}/{slug}` for an explicit city, else the inferred one, else the chooser.
     *
     * @param  array<string, scalar>  $query
     */
    public static function item(string $slug, ?City $city = null, array $query = []): string
    {
        $city ??= app(CityContext::class)->current();

        if (! $city) {
            return route('customer.city.choose', ['to' => 'services/'.$slug] + $query);
        }

        $url = url('/'.$city->slug.'/services/'.$slug);

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }
}
