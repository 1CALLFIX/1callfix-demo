{{--
    Search box with live suggestions (Phase C; autocomplete widened in the
    homepage-search-banners work).

    ── Why it is a <form> ────────────────────────────────────────────────────
    wire:submit means Enter works, the browser shows a search affordance, and
    the control behaves like a search box. Submitting REDIRECTS to the search
    screen, so the result has a shareable URL and a real history entry.

    ── Autocomplete behaviour ───────────────────────────────────────────────
    - Focus with an empty field shows a default list (most-booked / newest
      services). search-bar.js posts `focusField` on first focus.
    - Typing (≥ 2 chars, debounced) shows matching categories as their own
      group, then matching services grouped under their category name.
    - Arrow keys move a visual cursor through the options, Enter opens the
      active one, Escape closes the list. That cursor lives in
      resources/js/search-bar.js; everything it drives is in this markup
      (`data-search-input`, `data-search-option`, the option ids).

    ── Accessibility ─────────────────────────────────────────────────────────
    APG combobox pattern: role="combobox" with aria-expanded / aria-controls
    / aria-autocomplete / aria-activedescendant on the input; the popup is a
    role="listbox" of role="option" links, sectioned with role="group" +
    aria-labelledby. A polite live region reports how many suggestions were
    found. Without JavaScript every option is still a reachable link.

    ── Why debounced ────────────────────────────────────────────────────────
    Every keystroke is a server round trip in Livewire. 250ms collapses a
    typing burst into one request. The minimum length is enforced again in
    SearchBar::suggestionPayload() — the debounce is an optimisation, never
    the guard.
--}}

@php
    $listId = 'search-suggestions-'.$this->getId();
    $inputId = 'search-input-'.$this->getId();
    $hasResults = $showSuggestions && $resultCount > 0;
    // One running counter across every group so each option gets a stable,
    // unique id for aria-activedescendant and the arrow-key cursor.
    $optSeq = 0;
@endphp

<form wire:submit="submit"
      role="search"
      data-search-bar
      @class(['relative', 'w-full' => ! $compact, 'w-full sm:w-64 lg:w-80' => $compact])>

    <label for="{{ $inputId }}" class="sr-only">Search for a service</label>

    <div class="flex gap-2">
        <div class="relative flex-1">
            <span aria-hidden="true" class="pointer-events-none absolute inset-y-0 left-3 grid place-items-center">
                <x-icon name="magnifying-glass" class="h-5 w-5 text-slate-400" />
            </span>

            <input id="{{ $inputId }}"
                   type="search"
                   data-search-input
                   wire:model.live.debounce.250ms="term"
                   wire:keydown.escape="dismiss"
                   autocomplete="off"
                   role="combobox"
                   aria-expanded="{{ $hasResults ? 'true' : 'false' }}"
                   aria-controls="{{ $listId }}"
                   aria-autocomplete="list"
                   placeholder="Search for a service"
                   @class([
                       'customer-search block w-full rounded-lg border border-slate-300 bg-white py-3 pl-11 text-base text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-slate-900 focus:outline focus:outline-2 focus:outline-offset-0 focus:outline-slate-900',
                       'min-h-12 pr-10' => true,
                   ])>

            @if ($term !== '')
                <button type="button"
                        wire:click="clear"
                        class="absolute inset-y-0 right-0 grid w-10 place-items-center text-slate-400 transition hover:text-slate-900 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-slate-900">
                    <span class="sr-only">Clear search</span>
                    <x-icon name="x-circle" class="h-5 w-5" />
                </button>
            @endif
        </div>

        @unless ($compact)
            <button type="submit"
                    class="inline-flex min-h-12 items-center justify-center rounded-lg bg-slate-900 px-6 text-sm font-semibold text-white transition hover:bg-slate-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                Search
            </button>
        @endunless
    </div>

    <p wire:loading wire:target="term" class="mt-1 text-xs text-slate-500">Searching…</p>

    <p role="status" aria-live="polite" class="sr-only">
        @if ($showSuggestions)
            {{ $resultCount }} {{ \Illuminate\Support\Str::plural('suggestion', $resultCount) }} available
        @endif
    </p>

    @if ($showSuggestions)
        <div class="absolute inset-x-0 top-full z-50 mt-1 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-lg"
             data-search-panel>

            {{-- ---------- Default list (empty, focused field) ---------- --}}
            @if ($isDefault)
                @if ($defaultServices->isNotEmpty())
                    <p id="{{ $listId }}-h-default" class="px-4 pt-3 pb-1 text-xs font-semibold uppercase tracking-wide text-slate-400">
                        {{ $defaultHeading }}
                    </p>
                    <div id="{{ $listId }}" role="listbox" aria-label="{{ $defaultHeading }}"
                         class="max-h-80 overflow-y-auto pb-1">
                        @foreach ($defaultServices as $service)
                            @php $optId = $listId.'-opt-'.$optSeq++; @endphp
                            <a href="{{ route('customer.services.show', $service) }}"
                               role="option" id="{{ $optId }}" data-search-option aria-selected="false" tabindex="-1"
                               class="flex min-h-12 items-center gap-3 px-4 py-2 text-left transition hover:bg-slate-50 data-[active=true]:bg-slate-100 focus-visible:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-slate-900">
                                <x-customer.result-icon :name="$service->category?->name ?? $service->name"
                                                        :color="$service->category?->color"
                                                        :image-url="$service->category?->image_url" />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-medium text-slate-900">{{ $service->name }}</span>
                                    @if ($service->category)
                                        <span class="block truncate text-xs text-slate-500">{{ $service->category->name }}</span>
                                    @endif
                                </span>
                            </a>
                        @endforeach
                    </div>
                @else
                    <p id="{{ $listId }}" class="px-4 py-4 text-sm text-slate-600">
                        Start typing to search for a service.
                    </p>
                @endif

            {{-- ---------- No matches for a typed term ---------- --}}
            @elseif ($resultCount === 0)
                <p id="{{ $listId }}" class="px-4 py-4 text-sm text-slate-600">
                    Nothing matched “{{ $term }}”. Try a shorter word, or
                    <a href="{{ route('customer.categories.index') }}" class="font-medium text-slate-900 underline underline-offset-4">browse the categories</a>.
                </p>

            {{-- ---------- Matches, grouped ---------- --}}
            @else
                <div id="{{ $listId }}" role="listbox" aria-label="Search suggestions"
                     class="max-h-96 overflow-y-auto">

                    @if ($matchedCategories->isNotEmpty())
                        <div role="group" aria-labelledby="{{ $listId }}-h-categories">
                            <p id="{{ $listId }}-h-categories" class="px-4 pt-3 pb-1 text-xs font-semibold uppercase tracking-wide text-slate-400">
                                Categories
                            </p>
                            @foreach ($matchedCategories as $category)
                                @php $optId = $listId.'-opt-'.$optSeq++; @endphp
                                <a href="{{ route('customer.categories.show', $category) }}"
                                   role="option" id="{{ $optId }}" data-search-option aria-selected="false" tabindex="-1"
                                   class="flex min-h-12 items-center gap-3 px-4 py-2 text-left transition hover:bg-slate-50 data-[active=true]:bg-slate-100 focus-visible:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-slate-900">
                                    <x-customer.result-icon :name="$category->name"
                                                            :color="$category->color"
                                                            :image-url="$category->image_url" />
                                    <span class="min-w-0 flex-1 truncate text-sm font-medium text-slate-900">{{ $category->name }}</span>
                                    <span class="shrink-0 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-medium text-slate-500">Category</span>
                                </a>
                            @endforeach
                        </div>
                    @endif

                    @foreach ($serviceGroups as $group)
                        @php $groupHeadingId = $listId.'-h-g'.$loop->index; @endphp
                        <div role="group" aria-labelledby="{{ $groupHeadingId }}">
                            <p id="{{ $groupHeadingId }}" class="px-4 pt-3 pb-1 text-xs font-semibold uppercase tracking-wide text-slate-400">
                                {{ $group['name'] }}
                            </p>
                            @foreach ($group['services'] as $service)
                                @php $optId = $listId.'-opt-'.$optSeq++; @endphp
                                <a href="{{ route('customer.services.show', $service) }}"
                                   role="option" id="{{ $optId }}" data-search-option aria-selected="false" tabindex="-1"
                                   class="flex min-h-12 items-center gap-3 px-4 py-2 text-left transition hover:bg-slate-50 data-[active=true]:bg-slate-100 focus-visible:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-slate-900">
                                    <x-customer.result-icon :name="$group['category']?->name ?? $service->name"
                                                            :color="$group['category']?->color"
                                                            :image-url="$group['category']?->image_url" />
                                    <span class="min-w-0 flex-1 truncate text-sm font-medium text-slate-900">{{ $service->name }}</span>
                                </a>
                            @endforeach
                        </div>
                    @endforeach
                </div>

                <a href="{{ route('customer.search', ['q' => trim($term)]) }}"
                   class="flex min-h-12 items-center justify-center border-t border-slate-200 bg-slate-50 px-4 text-sm font-medium text-slate-700 transition hover:text-slate-900 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-slate-900">
                    See all results for “{{ $term }}”
                </a>
            @endif
        </div>
    @endif
</form>
