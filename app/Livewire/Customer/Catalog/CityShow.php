<?php

namespace App\Livewire\Customer\Catalog;

use App\Livewire\Customer\Concerns\ResolvesCatalogContext;
use App\Models\City;
use App\Models\CityPageContent;
use App\Services\Seo\CityPageSeo;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * F3: a city's public landing page, /{city}: the categories offered there, with the franchise's own
 * intro copy. Liveness (active city + active franchise) and QA hiding are enforced by CityRouteController
 * before this mounts.
 */
class CityShow extends Component
{
    use ResolvesCatalogContext;

    #[Locked]
    public int $cityId;

    public function mount(City $city): void
    {
        $this->cityId = $city->id;
    }

    public function render()
    {
        $city = City::findOrFail($this->cityId);
        $seo = app(CityPageSeo::class)->for(
            $city,
            CityPageContent::SUBJECT_CITY,
            0,
            'Home services in '.$city->name,
            'Book verified local professionals in '.$city->name.' for repairs, installation and maintenance.',
        );

        return view('livewire.customer.catalog.city-show', [
            'city' => $city,
            'categories' => $this->catalog()->categories()->get(),
            'intro' => $seo['intro'],
            'heading' => $seo['title'],
        ])->layout('components.layouts.customer', [
            'title' => $seo['title'],
            'indexable' => $seo['indexable'],
            'metaDescription' => $seo['metaDescription'],
        ]);
    }
}
