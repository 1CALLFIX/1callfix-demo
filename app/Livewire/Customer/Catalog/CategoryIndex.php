<?php

namespace App\Livewire\Customer\Catalog;

use App\Livewire\Customer\Concerns\ResolvesCatalogContext;
use Livewire\Component;

/**
 * The full category explorer (Phase C) — every active Service-vertical
 * category, with a live count of the services inside it and its own
 * subcategories listed beneath, so a customer can see the whole taxonomy on
 * one screen instead of guessing from the homepage's eight shortcuts.
 *
 * Counts come from `withCount` constrained to active services, so the number
 * on the tile is the number of services the customer will actually find
 * after clicking. Categories with zero active services are still shown, with
 * the count omitted rather than rendered as "0 services" — the category
 * exists and the admin published it; the honest signal is silence, not a
 * zero that reads like an error.
 */
class CategoryIndex extends Component
{
    use ResolvesCatalogContext;

    public function render()
    {
        $categories = $this->catalog()->categories()
            ->withCount(['services' => fn ($q) => $q->where('is_active', true)])
            ->with(['subcategories' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')->orderBy('name')])
            ->get();

        return view('livewire.customer.catalog.category-index', [
            'categories' => $categories,
            'banners' => $this->bannersFor('mid'),
        ])->layout('components.layouts.customer', [
            'title' => 'All categories',
            'metaDescription' => 'Browse every home service category available in your area.',
        ]);
    }
}
