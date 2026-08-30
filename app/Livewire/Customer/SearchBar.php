<?php

namespace App\Livewire\Customer;

use App\Livewire\Customer\Concerns\ResolvesCatalogContext;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * The header / hero search box with an instant suggestion dropdown
 * (Phase C, autocomplete widened in the homepage-search-banners work).
 *
 * Deliberately thin: it shows a handful of live matches and hands off to the
 * full Search screen for everything else. It shares that screen's query
 * layer exactly (ServiceCatalogQuery), so a suggestion that appears here is
 * guaranteed to appear there — a dropdown that disagrees with the results
 * page is worse than no dropdown.
 *
 * ── What the dropdown shows ───────────────────────────────────────────────
 *  - On focus with an empty field: a default list of the most-booked
 *    services for the viewer's franchise (falling back to newest when the
 *    catalog has no booking history), so the control is useful before a
 *    single key is pressed.
 *  - While typing (≥ 2 chars): matching `service_categories` as their own
 *    group, then matching `services` grouped under their category name.
 *    Both come straight from ServiceCatalogQuery — no separate search.
 *
 * All matching is local LIKE over columns this app already stores. No
 * external API, no search index.
 *
 * `$compact` switches between the header's icon-width control and the hero's
 * full-width one. Same component, same behaviour, so the two can never drift
 * apart in what they match.
 *
 * Keyboard navigation (arrow keys to move, Enter to open, Escape to close)
 * is driven by resources/js/search-bar.js against the ARIA combobox markup
 * this renders — the server owns the list, the browser owns the cursor.
 *
 * Submitting redirects to the Search screen rather than rendering results in
 * place: that gives a shareable URL, a back-button-able history entry, and
 * pagination — none of which a dropdown can offer.
 */
class SearchBar extends Component
{
    use ResolvesCatalogContext;

    public string $term = '';
    public bool $compact = false;

    /**
     * Whether the dropdown is open. Closed on every fresh render of a page,
     * opened by focusing or typing — so a suggestion list never survives a
     * navigation.
     */
    public bool $showSuggestions = false;

    /**
     * Set once the field has been focused this render cycle. Lets an empty
     * field still show the default list, and keeps it open after "clear".
     */
    public bool $focused = false;

    private const MIN_LENGTH = 2;
    private const SERVICE_LIMIT = 6;
    private const CATEGORY_LIMIT = 4;
    private const DEFAULT_LIMIT = 6;

    public function mount(bool $compact = false, string $term = ''): void
    {
        $this->compact = $compact;
        $this->term = $term;
    }

    /**
     * The input gained focus (dispatched by search-bar.js). Opens the
     * dropdown even when the field is empty so the default list can show.
     */
    public function focusField(): void
    {
        $this->focused = true;
        $this->showSuggestions = true;
    }

    public function updatedTerm(): void
    {
        $this->focused = true;

        $term = trim($this->term);

        // Empty + focused → default list. Otherwise the term has to clear the
        // useful-length bar; a single character matches most of the catalog
        // and is a list nobody can use. The debounce on the input is only an
        // optimisation — this length guard is the real gate.
        $this->showSuggestions = $term === ''
            ? true
            : mb_strlen($term) >= self::MIN_LENGTH;
    }

    public function dismiss(): void
    {
        $this->showSuggestions = false;
    }

    public function clear(): void
    {
        $this->term = '';
        // Clearing is usually the start of a fresh search, not a dismissal —
        // keep the dropdown open on the default list if the field is focused.
        $this->showSuggestions = $this->focused;
    }

    /** Enter, or the Search button. An empty term goes to the search screen unfiltered rather than doing nothing. */
    public function submit()
    {
        $term = trim($this->term);

        return redirect()->route('customer.search', $term === '' ? [] : ['q' => $term]);
    }

    public function render()
    {
        return view('livewire.customer.search-bar', $this->suggestionPayload() + [
            'minLength' => self::MIN_LENGTH,
        ]);
    }

    /**
     * The whole dropdown, resolved once per render.
     *
     * @return array{
     *     isDefault: bool,
     *     defaultHeading: ?string,
     *     defaultServices: Collection,
     *     matchedCategories: Collection,
     *     serviceGroups: Collection,
     *     resultCount: int
     * }
     */
    private function suggestionPayload(): array
    {
        $empty = [
            'isDefault' => false,
            'defaultHeading' => null,
            'defaultServices' => collect(),
            'matchedCategories' => collect(),
            'serviceGroups' => collect(),
            'resultCount' => 0,
        ];

        $term = trim($this->term);

        // Closed, or a below-threshold term typed without a focus event:
        // render nothing at all.
        if (! $this->showSuggestions || ($term !== '' && mb_strlen($term) < self::MIN_LENGTH)) {
            return $empty;
        }

        if ($term === '') {
            [$heading, $services] = $this->defaultServices();

            return array_merge($empty, [
                'isDefault' => true,
                'defaultHeading' => $heading,
                'defaultServices' => $services,
                'resultCount' => $services->count(),
            ]);
        }

        $matchedCategories = $this->catalog()->searchCategories($term)
            ->limit(self::CATEGORY_LIMIT)
            ->get(['id', 'name', 'slug', 'color', 'image']);

        $services = $this->catalog()->searchServices($term)
            ->with('category:id,name,slug,color,image')
            ->limit(self::SERVICE_LIMIT)
            ->get(['id', 'name', 'category_id', 'slug']);

        return array_merge($empty, [
            'matchedCategories' => $matchedCategories,
            'serviceGroups' => $this->groupByCategory($services),
            'resultCount' => $matchedCategories->count() + $services->count(),
        ]);
    }

    /**
     * Services bucketed under their owning category's name, in the order the
     * services came back (already sort_order, name). Each group carries the
     * category model so the row can show its icon.
     *
     * @param  Collection<int, \App\Models\Service>  $services
     * @return Collection<int, array{name: string, category: ?\App\Models\ServiceCategory, services: Collection}>
     */
    private function groupByCategory(Collection $services): Collection
    {
        return $services
            ->groupBy(fn ($service) => $service->category?->name ?? 'Other services')
            ->map(fn (Collection $items, string $name) => [
                'name' => $name,
                'category' => $items->first()->category,
                'services' => $items,
            ])
            ->values();
    }

    /**
     * The empty-field default: the viewer's most-booked services, or the
     * newest ones where there is no booking history to rank by (the same
     * fallback the homepage's "Most booked" section makes by hiding itself).
     *
     * @return array{0: string, 1: Collection<int, \App\Models\Service>}
     */
    private function defaultServices(): array
    {
        $popular = $this->catalog()->mostBooked($this->location()->franchiseId())
            ->with('category:id,name,slug,color,image')
            ->limit(self::DEFAULT_LIMIT)
            ->get();

        if ($popular->isNotEmpty()) {
            return ['Popular right now', $popular];
        }

        return ['Browse services', $this->catalog()->newest()
            ->with('category:id,name,slug,color,image')
            ->limit(self::DEFAULT_LIMIT)
            ->get()];
    }
}
