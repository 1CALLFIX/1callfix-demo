<?php

namespace App\Livewire\Customer\Hotels\Concerns;

use App\Models\Accommodation;
use App\Models\City;
use App\Services\Seo\CityContext;
use App\Services\Slug\SlugManager;
use App\Support\Modules;
use App\Support\Seo\CityModules;
use Illuminate\Support\Carbon;

/**
 * Hotels H2 — the public hotel pages live under the city (/{city}/hotels, /{city}/hotels/{hotel}), like Food. The city
 * must be live and Hotels switched on for it (Admin → Modules); an old city slug 301s with the query string kept.
 * Hotels shown are bookable (approved, active, home stays only when allowed) and in that city. Test hotels follow the
 * shop rule (Shop Settings → Hide test shops).
 */
trait InteractsWithHotelCity
{
    public City $hotelCity;

    protected function enterHotelCity(string $citySlug): bool
    {
        $found = SlugManager::resolveCity($citySlug);
        abort_if($found === null, 404);
        $this->hotelCity = $found['model'];

        if ($found['via'] === 'redirect') {
            $path = preg_replace('#^/'.preg_quote($citySlug, '#').'(?=/|$)#', '/'.$this->hotelCity->slug, '/'.ltrim(request()->path(), '/'));
            $query = request()->getQueryString();
            $this->redirect(url($path).($query ? '?'.$query : ''));

            return false;
        }

        abort_unless(app(CityContext::class)->isLive($this->hotelCity), 404);
        abort_unless(CityModules::liveIn($this->hotelCity, Modules::HOTEL), 404);
        app(CityContext::class)->enter($this->hotelCity);

        return true;
    }

    /** Bookable hotels in this city that a visitor may see. */
    public static function publicHotelsIn(City $city)
    {
        $query = Accommodation::query()->bookable()->whereHas('franchise', fn ($q) => $q->where('city_id', $city->id));
        if (\App\Support\MarketplaceSettings::on('marketplace.hide_test_shops') && ! \App\Models\Store::isTestViewer(auth()->user())) {
            $query->whereDoesntHave('provider.user', fn ($q) => $q->where('email', 'like', '%'.\App\Console\Commands\TestAccountsCommand::DOMAIN));
        }

        return $query;
    }

    /** Clean stay dates from the query: tonight → tomorrow by default, never in the past or over the Hotel Settings limits. */
    protected function stayDates(string $checkIn, string $checkOut): array
    {
        $today = Carbon::parse(app(\App\Services\TimezoneResolver::class)->format(now(), $this->hotelCity->franchises()->first(), 'Y-m-d'));
        // Whole dates only (a blank or broken date is "not given"): never two "now"s a moment apart.
        $in = preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkIn) === 1 ? rescue(fn () => Carbon::parse($checkIn)->startOfDay(), null, false) : null;
        $in = $in && $in->gte($today) && $in->lte($today->copy()->addDays((int) \App\Support\HotelSettings::get('hotel.booking_window_days'))) ? $in : $today;
        $out = preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkOut) === 1 ? rescue(fn () => Carbon::parse($checkOut)->startOfDay(), null, false) : null;
        $max = (int) \App\Support\HotelSettings::get('hotel.max_nights');
        $out = $out && $out->gt($in) && $in->diffInDays($out) <= $max ? $out : $in->copy()->addDay();

        return [$in->toDateString(), $out->toDateString(), (int) $in->diffInDays($out)];
    }
}
