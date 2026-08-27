<?php

namespace App\Livewire\Customer;

use App\Livewire\Customer\Concerns\ResolvesCatalogContext;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Customer discovery search (Phase C).
 *
 * ── One search implementation, not a second engine ────────────────────────
 * Every match here comes from App\Services\Catalog\ServiceCatalogQuery —
 * the same class the REST API's own catalog endpoints use — so search
 * results obey exactly the visibility rule the rest of the catalog does
 * (active, Service-vertical, active category). There is no separate index,
 * no shadow copy of the catalog, and no second definition of what is
 * findable. See ServiceCatalogQuery::searchServices() for why this is a
 * widened LIKE rather than a full-text engine.
 *
 * ── Recent searches ───────────────────────────────────────────────────────
 * Held in the session, which is real, already-configured infrastructure
 * (`SESSION_DRIVER=database`) and the same place the customer's chosen zone
 * lives. Deliberately NOT written to the user record: search history is
 * personal data, storing it durably is a retention decision nobody has
 * taken, and a session entry disappears with the session. Terms are stored
 * as the customer typed them and re-escaped on use.
 *
 * ── The AI / natural-language filter ──────────────────────────────────────
 * App\Services\Ai\BookingNaturalLanguageFilter exists and is wired into the
 * ADMIN bookings screen. It parses a phrase into booking filters — customer
 * ids, statuses, date ranges over the `bookings` table — which is a
 * different question from "which catalog services match this phrase". It is
 * therefore not called here: pointing a bookings-filter parser at the
 * catalog would produce confident nonsense. Recorded as a Phase D+ item
 * (see the Phase C report) rather than half-wired.
 */
class Search extends Component
{
    use ResolvesCatalogContext;

    #[Url(as: 'q', except: '')]
    public string $query = '';

    public const SESSION_RECENT_KEY = 'customer.recent_searches';

    private const MIN_LENGTH = 2;
    private const SERVICE_LIMIT = 24;
    private const CATEGORY_LIMIT = 8;
    private const RECENT_LIMIT = 5;

    public function mount(): void
    {
        $this->rememberIfSearched();
    }

    /**
     * Runs on every keystroke (debounced in the template). The term is only
     * remembered once it is long enough to be a real search, so a half-typed
     * "a" never lands in the recent list.
     */
    public function updatedQuery(): void
    {
        $this->rememberIfSearched();
    }

    public function clear(): void
    {
        $this->query = '';
    }

    public function useRecent(string $term): void
    {
        $this->query = $term;
    }

    public function clearRecent(): void
    {
        session()->forget(self::SESSION_RECENT_KEY);
    }

    public function render()
    {
        $term = trim($this->query);
        $hasQuery = mb_strlen($term) >= self::MIN_LENGTH;

        $services = $hasQuery
            ? $this->cardsFrom($this->catalog()->searchServices($term), self::SERVICE_LIMIT)
            : collect();

        return view('livewire.customer.search', [
            'term' => $term,
            'hasQuery' => $hasQuery,
            'minLength' => self::MIN_LENGTH,
            'services' => $services,
            'categories' => $hasQuery ? $this->catalog()->searchCategories($term)->limit(self::CATEGORY_LIMIT)->get() : collect(),
            'subcategories' => $hasQuery
                ? $this->catalog()->searchSubcategories($term)->with('category:id,name,slug')->limit(self::CATEGORY_LIMIT)->get()
                : collect(),
            'suggestions' => $hasQuery ? collect() : $this->suggestions(),
            'recent' => $this->recent(),
            'currencySymbol' => $this->presenter()->currencySymbol(),
        ])->layout('components.layouts.customer', [
            'title' => $hasQuery ? 'Search: '.$term : 'Search',
        ]);
    }

    /**
     * What to offer before anything has been typed: the real category names,
     * which are the words a customer is most likely to be reaching for. Not
     * a curated list of invented "popular searches" — no search-volume data
     * exists anywhere in this application to derive one from honestly.
     *
     * @return Collection<int, \App\Models\ServiceCategory>
     */
    private function suggestions(): Collection
    {
        return $this->catalog()->categories()->limit(self::CATEGORY_LIMIT)->get();
    }

    /** @return Collection<int, string> */
    private function recent(): Collection
    {
        return collect(session(self::SESSION_RECENT_KEY, []))->take(self::RECENT_LIMIT)->values();
    }

    private function rememberIfSearched(): void
    {
        $term = trim($this->query);

        if (mb_strlen($term) < self::MIN_LENGTH) {
            return;
        }

        $recent = collect(session(self::SESSION_RECENT_KEY, []))
            // Case-insensitive de-duplication, most recent first, so
            // searching the same thing twice does not fill the list.
            ->reject(fn ($existing) => mb_strtolower($existing) === mb_strtolower($term))
            ->prepend($term)
            ->take(self::RECENT_LIMIT)
            ->values()
            ->all();

        session([self::SESSION_RECENT_KEY => $recent]);
    }
}
