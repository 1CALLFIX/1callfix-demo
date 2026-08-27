<?php

namespace App\Livewire\Customer;

use App\Livewire\Customer\Concerns\ResolvesCatalogContext;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * The header / hero search box with an instant suggestion dropdown
 * (Phase C).
 *
 * Deliberately thin: it shows a handful of live matches and hands off to the
 * full Search screen for everything else. It shares that screen's query
 * layer exactly (ServiceCatalogQuery::searchServices), so a suggestion that
 * appears here is guaranteed to appear there — a dropdown that disagrees
 * with the results page is worse than no dropdown.
 *
 * `$compact` switches between the header's icon-width control and the hero's
 * full-width one. Same component, same behaviour, so the two can never drift
 * apart in what they match.
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
     * opened by typing — so a suggestion list never survives a navigation.
     */
    public bool $showSuggestions = false;

    private const MIN_LENGTH = 2;
    private const SUGGESTION_LIMIT = 6;

    public function mount(bool $compact = false, string $term = ''): void
    {
        $this->compact = $compact;
        $this->term = $term;
    }

    public function updatedTerm(): void
    {
        $this->showSuggestions = mb_strlen(trim($this->term)) >= self::MIN_LENGTH;
    }

    public function dismiss(): void
    {
        $this->showSuggestions = false;
    }

    public function clear(): void
    {
        $this->reset('term', 'showSuggestions');
    }

    /** Enter, or the Search button. An empty term goes to the search screen unfiltered rather than doing nothing. */
    public function submit()
    {
        $term = trim($this->term);

        return redirect()->route('customer.search', $term === '' ? [] : ['q' => $term]);
    }

    public function render()
    {
        return view('livewire.customer.search-bar', [
            'suggestions' => $this->suggestions(),
            'minLength' => self::MIN_LENGTH,
        ]);
    }

    /**
     * Live matches, capped tight. Only the columns the dropdown actually
     * renders are loaded, and only when the term is long enough to be
     * meaningful — a one-character search would match most of the catalog
     * and cost a full scan for a list nobody can use.
     *
     * @return Collection<int, \App\Models\Service>
     */
    private function suggestions(): Collection
    {
        $term = trim($this->term);

        if (! $this->showSuggestions || mb_strlen($term) < self::MIN_LENGTH) {
            return collect();
        }

        return $this->catalog()->searchServices($term)
            ->with('category:id,name')
            ->limit(self::SUGGESTION_LIMIT)
            ->get(['id', 'name', 'category_id', 'slug']);
    }
}
