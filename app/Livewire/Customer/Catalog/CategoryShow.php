<?php

namespace App\Livewire\Customer\Catalog;

use App\Livewire\Customer\Concerns\ResolvesCatalogContext;
use App\Models\ServiceCategory;
use App\Support\Modules;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * One category's services, with its subcategory rail, in-category search and
 * sort (Phase C).
 *
 * ── 404 discipline ────────────────────────────────────────────────────────
 * Route-model binding resolves the slug; it does NOT know about `is_active`
 * or `module`. mount() applies both, so an inactive category, or a category
 * belonging to another vertical (Marketplace, Hotel, ...), is a 404 here
 * exactly as it is absent from `GET /api/categories`. A customer must never
 * reach a Hotel category through a URL on the home-services site, and an
 * unpublished category must not be discoverable by guessing its slug.
 *
 * ── Filters are server-side ───────────────────────────────────────────────
 * Subcategory, keyword and sort are all applied in the database query, never
 * by hiding rows in the browser. That is required, not stylistic: a
 * client-side filter over a paginated result set filters only the page you
 * happen to be on, and silently lies about the rest.
 */
class CategoryShow extends Component
{
    use ResolvesCatalogContext;
    use WithPagination;

    /**
     * The resolved category id. #[Locked] because Livewire round-trips
     * public properties through the browser: without it a crafted payload
     * could repoint this screen at any category id, including an inactive
     * one or one from another vertical, bypassing mount()'s checks.
     */
    #[Locked]
    public int $categoryId;

    /** Selected subcategory, or null for "all". Shareable/bookmarkable via the URL. */
    #[Url(as: 'sub', except: null)]
    public ?int $subcategory = null;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'sort', except: 'recommended')]
    public string $sort = 'recommended';

    /**
     * The sorts this screen offers, each backed by a real column.
     *
     * There is deliberately no "top rated" option. Rating is derived from
     * reviews joined through bookings (see ServiceRatingSummary) and is not
     * a column on `services`, so offering it would mean either a fragile
     * aggregate sort over the whole catalog or — worse — sorting only the
     * current page and calling it a catalog-wide ranking. Documented as a
     * Phase D+ dependency rather than faked.
     */
    public const SORTS = [
        'recommended' => 'Recommended',
        'price_low' => 'Price: low to high',
        'price_high' => 'Price: high to low',
        'newest' => 'Newest first',
    ];

    private const PER_PAGE = 12;

    public function mount(ServiceCategory $category): void
    {
        abort_unless($category->is_active && $category->module === Modules::SERVICE, 404);

        $this->categoryId = $category->id;

        // A subcategory arriving in the query string is untrusted input:
        // accept it only if it is an active subcategory of THIS category,
        // otherwise fall back to "all" rather than 404 — a stale bookmark
        // should still show the category, just unfiltered.
        if ($this->subcategory !== null && ! $this->isValidSubcategory($this->subcategory)) {
            $this->subcategory = null;
        }

        if (! array_key_exists($this->sort, self::SORTS)) {
            $this->sort = 'recommended';
        }
    }

    /** Any filter change must reset to page 1, or the customer lands on an empty page 3 of a 1-page result. */
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSort(): void
    {
        $this->resetPage();
    }

    public function selectSubcategory(?int $subcategoryId): void
    {
        $this->subcategory = ($subcategoryId !== null && $this->isValidSubcategory($subcategoryId)) ? $subcategoryId : null;
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'subcategory');
        $this->sort = 'recommended';
        $this->resetPage();
    }

    public function render()
    {
        $category = ServiceCategory::findOrFail($this->categoryId);
        $catalog = $this->catalog();

        $query = $catalog->services([
            'category_id' => $category->id,
            'subcategory_id' => $this->subcategory,
            'keyword' => $this->search,
        ]);

        $paginator = $this->applySort($query)
            ->with(['category:id,name,slug', 'subcategory:id,name'])
            ->paginate(self::PER_PAGE);

        return view('livewire.customer.catalog.category-show', [
            'category' => $category,
            'subcategories' => $this->subcategories($category),
            'paginator' => $paginator,
            // The presenter is fed the CURRENT PAGE's models only, so badges,
            // flash-sale prices and ratings are batched per page rather than
            // across the whole category.
            'cards' => $this->presenter()->cards($paginator->getCollection()),
            'banners' => $this->bannersFor('mid', $category->id),
            'currencySymbol' => $this->presenter()->currencySymbol(),
            'hasFilters' => $this->search !== '' || $this->subcategory !== null || $this->sort !== 'recommended',
        ])->layout('components.layouts.customer', [
            'title' => $category->name,
            'metaDescription' => $category->description ?: $category->name.' services, booked in minutes.',
        ]);
    }

    /**
     * Price sorts order by ServiceCatalogQuery::orderByEffectivePrice(),
     * the SQL form of Service::resolvePrice($franchiseId) — the STORED
     * cascade for this viewer's franchise.
     *
     * That is the whole quoted price only where no live flash sale applies.
     * Since Phase D checkout DOES apply the sale layer, a discounted card
     * can sit out of its true price position in a price sort. See
     * orderByEffectivePrice()'s own docblock for why the sale layer is not
     * expressible in the ordering SQL without reimplementing the discount
     * rules there, and for the per-customer plan entitlements that are
     * consumed at booking time and are not a property of a row at all.
     */
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

    /** @return Collection<int, \App\Models\ServiceSubcategory> */
    private function subcategories(ServiceCategory $category): Collection
    {
        return $this->catalog()->subcategories($category->id)->get();
    }

    private function isValidSubcategory(int $subcategoryId): bool
    {
        return $this->catalog()->subcategories($this->categoryId)->whereKey($subcategoryId)->exists();
    }
}
