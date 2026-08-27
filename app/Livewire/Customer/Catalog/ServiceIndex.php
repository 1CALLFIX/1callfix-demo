<?php

namespace App\Livewire\Customer\Catalog;

use App\Livewire\Customer\Concerns\ResolvesCatalogContext;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The whole service catalog on one paginated screen, with category filter,
 * keyword search and sort (Phase C).
 *
 * This is the "browse everything" counterpart to CategoryShow's "browse one
 * category". Both apply their filters in the database and both paginate, for
 * the same reason: filtering a paginated result set in the browser filters
 * only the page in front of you and misrepresents the rest.
 *
 * `offersOnly` powers the /offers route. It narrows to services with a
 * currently-active, scope-covering, not-sold-out flash sale, using the same
 * FlashSaleService call that computes the discounted price on each card — so
 * the list and the prices on it can never disagree about what is on offer.
 * With no live sales the screen renders an honest empty state rather than
 * falling back to full-price services under an "Offers" heading.
 */
class ServiceIndex extends Component
{
    use ResolvesCatalogContext;
    use WithPagination;

    /** Set by the route, not by the customer — /offers mounts this component with it on. */
    public bool $offersOnly = false;

    #[Url(as: 'category', except: null)]
    public ?int $category = null;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'sort', except: 'recommended')]
    public string $sort = 'recommended';

    private const PER_PAGE = 12;

    /**
     * `$offersOnly` is set by the ROUTE, never by the customer: the /offers
     * route name turns it on. It is also accepted as a mount argument so a
     * test can drive the offers view directly without faking a request.
     * There is deliberately no #[Url] on it — a query parameter would let a
     * visitor flip a screen's identity from the address bar.
     */
    public function mount(bool $offersOnly = false): void
    {
        $this->offersOnly = $offersOnly || request()->routeIs('customer.offers');

        // Untrusted query-string input: drop a category id that is not an
        // active Service-vertical category rather than filtering on it.
        if ($this->category !== null && ! $this->catalog()->categories()->whereKey($this->category)->exists()) {
            $this->category = null;
        }

        if (! array_key_exists($this->sort, CategoryShow::SORTS)) {
            $this->sort = 'recommended';
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSort(): void
    {
        $this->resetPage();
    }

    public function updatedCategory(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'category');
        $this->sort = 'recommended';
        $this->resetPage();
    }

    public function render()
    {
        $catalog = $this->catalog();

        $query = $catalog->services([
            'category_id' => $this->category,
            'keyword' => $this->search,
        ]);

        if ($this->offersOnly) {
            $ids = app(\App\Services\FlashSaleService::class)->activeServiceIdsFor($this->location()->viewerScope());
            // whereIn on an empty set correctly returns nothing — an Offers
            // screen with no live sales must be empty, not unfiltered.
            $query->whereIn('id', $ids->all());
        }

        $paginator = $this->applySort($query)
            ->with(['category:id,name,slug', 'subcategory:id,name'])
            ->paginate(self::PER_PAGE);

        return view('livewire.customer.catalog.service-index', [
            'paginator' => $paginator,
            'cards' => $this->presenter()->cards($paginator->getCollection()),
            'categories' => $catalog->categories()->get(),
            'banners' => $this->offersOnly ? collect() : $this->bannersFor('mid'),
            'currencySymbol' => $this->presenter()->currencySymbol(),
            'hasFilters' => $this->search !== '' || $this->category !== null || $this->sort !== 'recommended',
        ])->layout('components.layouts.customer', [
            'title' => $this->offersOnly ? 'Offers' : 'All services',
            'metaDescription' => $this->offersOnly
                ? 'Current offers and limited-time pricing on home services in your area.'
                : 'Browse every home service available in your area.',
        ]);
    }

    /** Identical rule to CategoryShow::applySort() — see that method's docblock. */
    private function applySort($query)
    {
        $franchiseId = $this->location()->franchiseId();

        return match ($this->sort) {
            'price_low' => $this->catalog()->orderByEffectivePrice($query, 'asc', $franchiseId),
            'price_high' => $this->catalog()->orderByEffectivePrice($query, 'desc', $franchiseId),
            'newest' => $query->reorder()->orderByDesc('created_at')->orderByDesc('id'),
            default => $query,
        };
    }
}
