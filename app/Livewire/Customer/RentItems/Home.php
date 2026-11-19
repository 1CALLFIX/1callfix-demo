<?php

namespace App\Livewire\Customer\RentItems;

use App\Livewire\Customer\RentItems\Concerns\InteractsWithItemCity;
use App\Models\RentalItem;
use App\Models\Setting;
use App\Models\Zone;
use App\Services\RentalItems\RentalItemService;
use App\Support\PostTypes;
use App\Support\RentalItemSettings;
use App\Support\SiteText;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Rent anything (owner decision 2026-11-18) — /{city}/rent: every item people rent out in the city, zone by zone where
 * "Rent anything" is on. Tap filters (kind, area, can deliver) come from Rental Settings; featured first, then the
 * newest, or cheapest first. A "Rent out your things" button for people who want to post.
 */
class Home extends Component
{
    use InteractsWithItemCity;

    /** Which kind of post this page lists: Rent anything here, Second-hand in SecondHand\Home (2026-11-19). */
    protected string $deal = PostTypes::RENT;

    #[Url(as: 'kind')]
    public string $kind = '';

    #[Url(as: 'area')]
    public string $area = '';

    #[Url(as: 'deliver')]
    public string $deliver = '';

    #[Url(as: 'sort')]
    public string $sort = '';

    public function mount(string $city): void
    {
        $this->enterItemCity($city);
    }

    public function clearFilters(): void
    {
        $this->reset('kind', 'area', 'deliver', 'sort');
    }

    /** One item card's words, shared with the Rentals page. A Second-hand price has no unit: "₹6,500". */
    public static function card(RentalItem $item, string $citySlug): array
    {
        $symbol = Setting::get('locale.currency_symbol', '₹');
        $deal = PostTypes::valid($item->deal);

        return [
            'url' => PostTypes::url($item, $citySlug),
            'price' => $item->price ? PostTypes::text($deal, 'card.price', ['price' => $symbol.number_format((float) $item->price), 'unit' => mb_strtolower((string) $item->price_unit)]) : null,
            'what' => trim(implode(' · ', array_filter([$item->category, $item->condition]))),
            'where' => trim(implode(' · ', array_filter([$item->zone?->name, $item->landmark]))),
            'cover' => $item->coverUrl(),
        ];
    }

    public function render()
    {
        $city = $this->itemCity;
        $scope = self::cityScope($city);
        $base = RentalItemService::publicIn($city, $this->deal);
        $items = (clone $base)->with(['zone', 'franchise'])
            ->when($this->kind !== '', fn ($q) => $q->where('category', $this->kind))
            ->when(ctype_digit($this->area), fn ($q) => $q->where('zone_id', (int) $this->area))
            ->when($this->deliver === '1', fn ($q) => $q->where('can_deliver', true))
            ->when($this->sort === 'cheap', fn ($q) => $q->orderBy('price'), fn ($q) => $q->orderByDesc('is_featured')->latest('approved_at'))
            ->limit(60)->get();
        $areaIds = (clone $base)->distinct()->pluck('zone_id')->filter();
        $brand = Setting::get('branding.platform_name', '1CallFix');
        $t = fn (string $k, array $r = []) => PostTypes::text($this->deal, $k, $r);

        return view('livewire.customer.rent-items.home', [
            'deal' => $this->deal,
            'createRoute' => PostTypes::route($this->deal, 'create'),
            'city' => $city,
            'items' => $items,
            'cards' => $items->mapWithKeys(fn (RentalItem $i) => [$i->id => self::card($i, $city->slug)]),
            'kinds' => RentalItemSettings::listIn(PostTypes::key($this->deal, 'categories'), $scope),
            'areas' => Zone::whereIn('id', $areaIds)->orderBy('name')->pluck('name', 'id'),
            'filtered' => ($this->kind.$this->area.$this->deliver) !== '',
        ])->layout('components.layouts.customer', [
            'title' => $t('meta.title', ['city' => $city->name]),
            'metaDescription' => $t('meta.description', ['city' => $city->name, 'brand' => $brand]),
            'indexable' => true,
        ]);
    }
}
