<?php

namespace App\Livewire\Customer\Halls;

use App\Livewire\Customer\Halls\Concerns\InteractsWithHallCity;
use App\Models\Hall;
use App\Models\Setting;
use App\Support\SiteText;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Function halls R2 — /{city}/halls: every bookable hall in the city (featured first, then the biggest), filterable by
 * kind. Each card: cover with ♡ Like + Share, name, kind, seats, AC, lowest slot price.
 */
class Home extends Component
{
    use InteractsWithHallCity;

    #[Url(as: 'kind')]
    public string $kind = '';

    public function mount(string $city): void
    {
        $this->enterHallCity($city);
    }

    /** One hall card's words, shared with the hall page header. */
    public static function card(Hall $hall, string $citySlug): array
    {
        $symbol = Setting::get('locale.currency_symbol', '₹');
        $from = $hall->fromPrice();

        return [
            'url' => route('customer.halls.show', [$citySlug, $hall->slug]),
            'from' => $from !== null ? SiteText::get('hall.card.from', ['price' => $symbol.number_format($from)]) : null,
            'seats' => $hall->seating_capacity ? SiteText::get('hall.card.seats', ['n' => number_format($hall->seating_capacity)]) : null,
            'ac' => $hall->is_ac ? SiteText::get('hall.card.ac') : SiteText::get('hall.card.non_ac'),
            'cover' => $hall->coverUrl(),
        ];
    }

    public function render()
    {
        $halls = self::publicHallsIn($this->hallCity)->with(['slots', 'franchise'])
            ->when($this->kind !== '', fn ($q) => $q->where('hall_type', $this->kind))
            ->orderByDesc('is_featured')->orderByDesc('seating_capacity')->limit(60)->get();
        $kinds = self::publicHallsIn($this->hallCity)->distinct()->orderBy('hall_type')->pluck('hall_type')->filter()->values();
        $brand = Setting::get('branding.platform_name', '1CallFix');

        return view('livewire.customer.halls.home', [
            'city' => $this->hallCity,
            'halls' => $halls,
            'cards' => $halls->mapWithKeys(fn (Hall $h) => [$h->id => self::card($h, $this->hallCity->slug)]),
            'kinds' => $kinds,
        ])->layout('components.layouts.customer', [
            'title' => SiteText::get('hall.meta.title', ['city' => $this->hallCity->name]),
            'metaDescription' => SiteText::get('hall.meta.description', ['city' => $this->hallCity->name, 'brand' => $brand]),
            'indexable' => true,
        ]);
    }
}
