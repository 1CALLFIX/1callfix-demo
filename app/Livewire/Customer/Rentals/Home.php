<?php

namespace App\Livewire\Customer\Rentals;

use App\Livewire\Customer\Halls\Home as HallsHome;
use App\Models\City;
use App\Models\Hall;
use App\Models\Setting;
use App\Services\Seo\CityContext;
use App\Services\Slug\SlugManager;
use App\Support\RentalKinds;
use App\Support\Seo\CityModules;
use App\Support\SiteText;
use Livewire\Component;

/**
 * One Rental (owner 2026-11-14) — /{city}/rentals, the page behind the one Rentals door: every kind of rental live in
 * the city (Rental on in Modules and the kind on in Rental Settings), each in its own colour with what can be booked.
 * Function halls have their own pages today; Properties, Vehicles and Equipment join here once their customer pages
 * are built (nothing is shown that a customer cannot book).
 */
class Home extends Component
{
    /** Kinds that have customer pages today, in the order they show. */
    public const KINDS_WITH_PAGES = [RentalKinds::HALLS];

    public City $rentalCity;

    public function mount(string $city): void
    {
        $found = SlugManager::resolveCity($city);
        abort_if($found === null, 404);
        $this->rentalCity = $found['model'];
        if ($found['via'] === 'redirect') {
            $query = request()->getQueryString();
            $this->redirect(url('/'.$this->rentalCity->slug.'/rentals').($query ? '?'.$query : ''));

            return;
        }
        abort_unless(app(CityContext::class)->isLive($this->rentalCity) && self::liveIn($this->rentalCity), 404);
        app(CityContext::class)->enter($this->rentalCity);
    }

    /** @return list<string> kinds with pages that are live in this city */
    public static function liveKinds(City $city): array
    {
        return array_values(array_filter(self::KINDS_WITH_PAGES, fn ($kind) => CityModules::rentalKindLiveIn($city, $kind)));
    }

    public static function liveIn(City $city): bool
    {
        return self::liveKinds($city) !== [];
    }

    /** What the Rentals door counts: everything bookable here. */
    public static function liveCountIn(City $city): int
    {
        $count = 0;
        foreach (self::liveKinds($city) as $kind) {
            $count += match ($kind) {
                RentalKinds::HALLS => HallsHome::publicHallsIn($city)->count(),
                default => 0,
            };
        }

        return $count;
    }

    public function render()
    {
        $city = $this->rentalCity;
        $sections = [];
        foreach (self::liveKinds($city) as $kind) {
            if ($kind === RentalKinds::HALLS) {
                $query = HallsHome::publicHallsIn($city);
                $halls = (clone $query)->with(['slots', 'franchise'])->orderByDesc('is_featured')->orderByDesc('seating_capacity')->limit(6)->get();
                $sections[] = [
                    'kind' => $kind, 'colour' => 'teal', 'icon' => '🏛️',
                    'title' => SiteText::get('rental.kind.halls'), 'line' => SiteText::get('rental.kind.halls.line'),
                    'url' => route('customer.halls.home', $city->slug), 'count' => (clone $query)->count(),
                    'items' => $halls->map(fn (Hall $h) => ['id' => 'hall:'.$h->id, 'name' => $h->name, 'kind' => $h->hall_type] + HallsHome::card($h, $city->slug))->all(),
                ];
            }
        }
        $brand = Setting::get('branding.platform_name', '1CallFix');

        return view('livewire.customer.rentals.home', ['city' => $city, 'sections' => $sections])
            ->layout('components.layouts.customer', [
                'title' => SiteText::get('rental.meta.title', ['city' => $city->name]),
                'metaDescription' => SiteText::get('rental.meta.description', ['city' => $city->name, 'brand' => $brand]),
                'indexable' => true,
            ]);
    }
}
