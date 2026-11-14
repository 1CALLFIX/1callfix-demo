<?php

namespace App\Livewire\Customer\Homes;

use App\Livewire\Customer\Homes\Concerns\InteractsWithHomeCity;
use App\Models\HouseListing;
use App\Models\Setting;
use App\Models\Zone;
use App\Services\Homes\HouseListingService;
use App\Support\HomeSettings;
use App\Support\SiteText;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Houses for rent — /{city}/homes: every home for rent in the city. Tap filters (kind, size, budget, furnishing, who can
 * rent, area) come from Rental Settings for this city, so each city shows its own sizes and budget steps. Featured
 * first, then the newest; or cheapest first.
 */
class Home extends Component
{
    use InteractsWithHomeCity;

    #[Url(as: 'type')]
    public string $type = '';

    #[Url(as: 'size')]
    public string $size = '';

    #[Url(as: 'budget')]
    public string $budget = '';

    #[Url(as: 'furnishing')]
    public string $furnishing = '';

    #[Url(as: 'for')]
    public string $tenant = '';

    #[Url(as: 'area')]
    public string $area = '';

    #[Url(as: 'sort')]
    public string $sort = '';

    public function mount(string $city): void
    {
        $this->enterHomeCity($city);
    }

    public function clearFilters(): void
    {
        $this->reset('type', 'size', 'budget', 'furnishing', 'tenant', 'area', 'sort');
    }

    /** One home card's words, shared with the home page and the Rentals page. */
    public static function card(HouseListing $home, string $citySlug): array
    {
        $symbol = Setting::get('locale.currency_symbol', '₹');

        return [
            'url' => route('customer.homes.show', [$citySlug, $home->slug]),
            'rent' => $home->rent ? SiteText::get('homes.card.rent', ['price' => $symbol.number_format((float) $home->rent)]) : null,
            'what' => trim(implode(' · ', array_filter([$home->size, $home->home_type]))),
            'where' => trim(implode(' · ', array_filter([$home->zone?->name, $home->landmark]))),
            'furnishing' => $home->furnishing,
            'ready' => $home->available_from === null || $home->available_from->isPast() ? SiteText::get('homes.card.ready_now')
                : SiteText::get('homes.card.ready_from', ['date' => $home->available_from->format('j M')]),
            'cover' => $home->coverUrl(),
        ];
    }

    public function render()
    {
        $city = $this->homeCity;
        $scope = self::cityScope($city);
        $base = HouseListingService::publicIn($city);
        $homes = (clone $base)->with(['zone', 'franchise'])
            ->when($this->type !== '', fn ($q) => $q->where('home_type', $this->type))
            ->when($this->size !== '', fn ($q) => $q->where('size', $this->size))
            ->when(ctype_digit($this->budget), fn ($q) => $q->where('rent', '<=', (int) $this->budget))
            ->when($this->furnishing !== '', fn ($q) => $q->where('furnishing', $this->furnishing))
            ->when($this->tenant !== '', fn ($q) => $q->whereJsonContains('tenants', $this->tenant))
            ->when(ctype_digit($this->area), fn ($q) => $q->where('zone_id', (int) $this->area))
            ->when($this->sort === 'cheap', fn ($q) => $q->orderBy('rent'), fn ($q) => $q->orderByDesc('is_featured')->latest('approved_at'))
            ->limit(60)->get();
        $areaIds = (clone $base)->distinct()->pluck('zone_id')->filter();
        $brand = Setting::get('branding.platform_name', '1CallFix');
        $symbol = Setting::get('locale.currency_symbol', '₹');

        return view('livewire.customer.homes.home', [
            'city' => $city,
            'homes' => $homes,
            'cards' => $homes->mapWithKeys(fn (HouseListing $h) => [$h->id => self::card($h, $city->slug)]),
            'types' => HomeSettings::listIn('homes.types', $scope),
            'sizes' => HomeSettings::listIn('homes.sizes', $scope),
            'furnishings' => HomeSettings::listIn('homes.furnishing', $scope),
            'tenantChoices' => HomeSettings::listIn('homes.tenants', $scope),
            'budgets' => collect(HomeSettings::numbersIn('homes.budget_steps', $scope))->mapWithKeys(fn ($b) => [(string) $b => SiteText::get('homes.filter.up_to', ['price' => $symbol.number_format($b)])])->all(),
            'areas' => Zone::whereIn('id', $areaIds)->orderBy('name')->pluck('name', 'id'),
            'filtered' => ($this->type.$this->size.$this->budget.$this->furnishing.$this->tenant.$this->area) !== '',
        ])->layout('components.layouts.customer', [
            'title' => SiteText::get('homes.meta.title', ['city' => $city->name]),
            'metaDescription' => SiteText::get('homes.meta.description', ['city' => $city->name, 'brand' => $brand]),
            'indexable' => true,
        ]);
    }
}
