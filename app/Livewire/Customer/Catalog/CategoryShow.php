<?php

namespace App\Livewire\Customer\Catalog;

use App\Livewire\Customer\Concerns\ResolvesCatalogContext;
use App\Models\Service;
use App\Models\ServiceCartItem;
use App\Models\ServiceCategory;
use App\Models\ServiceOptionGroup;
use App\Services\Customer\ServiceCartService;
use App\Support\SiteText;
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

    /** Most services one sectioned subcategory page shows (a subcategory is a handful; this only bounds a mistake). */
    private const SECTION_LIMIT = 60;

    /** "Added to your cart." after an Add on the sectioned page. */
    public string $cartNotice = '';

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

    // ===================== One page with sections (owner 2026-11-14) =====================
    // The Urban Company way: open Split AC and every service shows under coloured section headings
    // ("Service", "Repair & gas", "Install & move"), each with an Add button that puts that one real service,
    // at its own price, straight into the services cart. Nothing here prices anything: the cart and checkout
    // re-price every line exactly as before.

    /** Add one of $serviceId to the cart. Only a service of THIS category + the chosen subcategory. */
    public function addService(int $serviceId, ServiceCartService $cart): void
    {
        $this->cartNotice = '';
        $this->resetErrorBag();

        $service = $this->sectionService($serviceId);
        if (! $service) {
            return;
        }

        if (! auth()->check()) {
            $intended = \App\Support\Seo\PublicUrl::category(ServiceCategory::findOrFail($this->categoryId)).'?sub='.$this->subcategory;
            session()->put('customer.intended', parse_url($intended, PHP_URL_PATH).'?sub='.$this->subcategory);
            $this->redirectRoute('customer.login', ['intended' => $intended]);

            return;
        }

        // A question that must be answered first (e.g. AC brand) is asked on the service's own page.
        if ($this->needsAnswersFirst($service)) {
            $this->redirect(\App\Support\Seo\PublicUrl::service($service), navigate: true);

            return;
        }

        try {
            $cart->add(auth()->user(), $service, [], null, '', 1);
        } catch (\RuntimeException $e) {
            $this->addError('cart', $e->getMessage());

            return;
        }

        $this->cartNotice = SiteText::get('service.section.added');
        $this->dispatch('cart-updated');
    }

    /** Take one of $serviceId back out of the cart (the − on the stepper). */
    public function removeOne(int $serviceId, ServiceCartService $cart): void
    {
        $this->cartNotice = '';
        if (! auth()->check() || ! $this->sectionService($serviceId)) {
            return;
        }

        $line = ServiceCartItem::where('user_id', auth()->id())->where('service_id', $serviceId)->latest('id')->first();
        if ($line) {
            $cart->updateQuantity($line, $line->quantity - 1);
            $this->dispatch('cart-updated');
        }
    }

    private function sectionService(int $serviceId): ?Service
    {
        if ($this->subcategory === null) {
            return null;
        }

        return $this->catalog()->services(['category_id' => $this->categoryId, 'subcategory_id' => $this->subcategory])
            ->whereKey($serviceId)->first();
    }

    private function needsAnswersFirst(Service $service): bool
    {
        return ServiceOptionGroup::where('service_id', $service->id)->where('is_required', true)
            ->whereHas('options', fn ($q) => $q->where('is_active', true)->where('price_delta', 0))
            ->exists();
    }

    /**
     * The sections for the chosen subcategory, or null for the normal card grid. Only when a subcategory is picked,
     * nothing is searched or sorted, and at least one of its services has a section.
     *
     * @return array{sections: array<int, array{name: string, items: array<int, array>}>, counts: array<int, int>, cartCount: int}|null
     */
    private function sectionedPage(int $categoryId): ?array
    {
        if ($this->subcategory === null || trim($this->search) !== '' || $this->sort !== 'recommended') {
            return null;
        }

        $filters = ['category_id' => $categoryId, 'subcategory_id' => $this->subcategory];
        $mains = $this->catalog()->services($filters + ['main_only' => true])
            ->with(['category:id,name,slug', 'subcategory:id,name'])->limit(self::SECTION_LIMIT)->get();

        if ($mains->whereNotNull('page_section')->isEmpty()) {
            return null;
        }

        $groupedIds = $mains->filter(fn (Service $s) => filled($s->choice_group_name))->pluck('id');
        $members = $groupedIds->isEmpty() ? collect() : $this->catalog()->services($filters)
            ->whereIn('choice_of', $groupedIds)->with(['category:id,name,slug', 'subcategory:id,name'])->get();

        $cards = $this->presenter()->cards($mains->concat($members))->keyBy(fn (array $card) => $card['service']->id);
        $more = SiteText::get('service.section.more');

        $sections = [];
        foreach ($mains as $main) {
            $name = trim((string) $main->page_section) ?: $more;
            $choices = collect([$main])->concat($members->where('choice_of', $main->id)->sortBy([['base_price', 'asc'], ['id', 'asc']]))
                ->map(fn (Service $s) => ['id' => $s->id, 'label' => $s->choice_label ?: $s->name, 'card' => $cards[$s->id]])
                ->values()->all();

            $sections[$name] ??= ['name' => $name, 'items' => []];
            $sections[$name]['items'][] = ['card' => $cards[$main->id], 'choices' => count($choices) > 1 ? $choices : []];
        }

        $counts = auth()->check()
            ? ServiceCartItem::where('user_id', auth()->id())->whereIn('service_id', $cards->keys())
                ->selectRaw('service_id, SUM(quantity) as qty')->groupBy('service_id')->pluck('qty', 'service_id')
                ->map(fn ($q) => (int) $q)->all()
            : [];

        return [
            'sections' => array_values($sections),
            'counts' => $counts,
            'cartCount' => auth()->check() ? (int) ServiceCartItem::where('user_id', auth()->id())->sum('quantity') : 0,
        ];
    }

    public function render()
    {
        $category = ServiceCategory::findOrFail($this->categoryId);
        $catalog = $this->catalog();
        // F3: franchise-edited copy and the "real page" indexing decision for this category in the URL's city.
        $seo = $this->citySeo(
            \App\Models\CityPageContent::SUBJECT_CATEGORY,
            $category->id,
            $category->name,
            $category->description ?: $category->name.' services, booked in minutes.',
        );

        $query = $catalog->services([
            'category_id' => $category->id,
            'subcategory_id' => $this->subcategory,
            'keyword' => $this->search,
            // Grouped choices show as one card; a search still finds each choice by its own name.
            'main_only' => trim((string) $this->search) === '',
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
            'sectioned' => $this->sectionedPage($category->id),
            'banners' => $this->bannersFor('mid', $category->id),
            'currencySymbol' => $this->presenter()->currencySymbol(),
            'hasFilters' => $this->search !== '' || $this->subcategory !== null || $this->sort !== 'recommended',
            'cityIntro' => $seo['intro'],
        ])->layout('components.layouts.customer', [
            'title' => $seo['title'],
            // A searched, sorted or subcategory-filtered list is a duplicate of the plain category page.
            'indexable' => $seo['indexable'] && ! ($this->search !== '' || $this->subcategory !== null || $this->sort !== 'recommended'),
            'ogImage' => $category->image_url,
            'metaDescription' => $seo['metaDescription'],
            'schema' => ($city = request()->attributes->get('f3.city'))
                ? [\App\Services\Seo\SeoSettings::breadcrumbs([
                    ['Home', \App\Support\Seo::canonicalBase().'/'],
                    ['Services in '.$city->name, \App\Support\Seo::absoluteUrl(\App\Support\Seo\PublicUrl::services($city))],
                    [$category->name, \App\Support\Seo::canonicalUrl()],
                ])]
                : [],
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
