<?php

namespace App\Livewire\Customer\Homes;

use App\Livewire\Customer\Homes\Concerns\InteractsWithHomeCity;
use App\Models\HouseListing;
use App\Models\Setting;
use App\Services\Homes\HouseListingService;
use App\Support\HomeSettings;
use App\Support\SiteText;
use Livewire\Component;

/**
 * Houses for rent — /{city}/homes/{home}: every photo (♡ Like + Share), rent, deposit, maintenance, size, floor, facing,
 * furnishing, when it is ready, who can rent, facilities in their Rental Settings sections, house rules, about, the
 * area and landmark (the exact address only after the owner accepts a visit). The contact panel (ask for a visit, see
 * the owner's number) loads on its own request, so this page stays public and cacheable.
 */
class Show extends Component
{
    use InteractsWithHomeCity;

    public string $homeSlug = '';

    public function mount(string $city, string $homeSlug): void
    {
        if (! $this->enterHomeCity($city)) {
            return;
        }
        $this->homeSlug = $homeSlug;
        abort_unless(HouseListingService::publicIn($this->homeCity)->where('slug', $homeSlug)->exists(), 404);
    }

    /** @return list<array{title: string, items: list<string>}> the home's ticked facilities in the sections of its area */
    public static function facilityGroups(HouseListing $home): array
    {
        $ticked = array_values(array_unique((array) ($home->amenities ?? [])));
        $out = [];
        $placed = [];
        foreach (HomeSettings::amenityGroups($home->scope()) as $g) {
            $items = array_values(array_intersect($g['items'], $ticked));
            if ($items !== []) {
                $out[] = ['title' => $g['title'], 'items' => $items];
                $placed = array_merge($placed, $items);
            }
        }
        $rest = array_values(array_diff($ticked, $placed));
        if ($rest !== []) {
            $out[] = ['title' => $out === [] ? '' : SiteText::get('hall.show.more_facilities'), 'items' => $rest];
        }

        return $out;
    }

    public function render()
    {
        $home = HouseListingService::publicIn($this->homeCity)->where('slug', $this->homeSlug)->with(['zone', 'franchise'])->firstOrFail();
        $symbol = Setting::get('locale.currency_symbol', '₹');
        $money = fn ($v) => $v ? $symbol.number_format((float) $v) : null;
        $unit = HomeSettings::areaUnit($home->scope());
        $t = fn ($k, $r = []) => SiteText::get($k, $r);

        return view('livewire.customer.homes.show', [
            'home' => $home,
            'city' => $this->homeCity,
            'card' => Home::card($home, $this->homeCity->slug),
            'photos' => array_values(array_filter(array_merge([$home->coverUrl()], $home->photoUrls()))),
            'facts' => array_values(array_filter([
                [$t('homes.show.rent'), $money($home->rent).' '.$t('homes.show.per_month'), 'bg-emerald-50 text-emerald-900'],
                $home->deposit ? [$t('homes.show.deposit'), $money($home->deposit), 'bg-sky-50 text-sky-900'] : null,
                [$t('homes.show.maintenance'), $home->maintenance ? $money($home->maintenance) : $t('homes.show.included'), 'bg-violet-50 text-violet-900'],
                [$t('homes.show.size'), $home->size, 'bg-amber-50 text-amber-900'],
                $home->area ? [$t('homes.show.area'), number_format($home->area).' '.$unit, 'bg-pink-50 text-pink-900'] : null,
                $home->floor ? [$t('homes.show.floor'), $home->floor, 'bg-teal-50 text-teal-900'] : null,
                $home->facing ? [$t('homes.show.facing'), $home->facing, 'bg-orange-50 text-orange-900'] : null,
                [$t('homes.show.furnishing'), $home->furnishing, 'bg-indigo-50 text-indigo-900'],
                [$t('homes.show.ready'), $home->available_from === null || $home->available_from->isPast() ? $t('homes.card.ready_now') : $home->available_from->format('j M Y'), 'bg-lime-50 text-lime-900'],
            ])),
            'facilityGroups' => self::facilityGroups($home),
            'schema' => array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'Accommodation',
                'name' => $home->title,
                'description' => $home->about,
                'image' => $home->coverUrl() ? [$home->coverUrl()] : null,
                'address' => ['@type' => 'PostalAddress', 'addressLocality' => $this->homeCity->name, 'streetAddress' => $home->zone?->name, 'addressCountry' => 'IN'],
                'amenityFeature' => collect((array) $home->amenities)->map(fn ($a) => ['@type' => 'LocationFeatureSpecification', 'name' => $a, 'value' => true])->values()->all() ?: null,
            ]),
        ])->layout('components.layouts.customer', [
            'title' => $home->title.' · '.$this->homeCity->name,
            'metaDescription' => \Illuminate\Support\Str::limit(trim($home->title.'. '.$money($home->rent).' per month. '.$home->furnishing.'. '.$home->about), 155),
            'indexable' => true,
        ]);
    }
}
