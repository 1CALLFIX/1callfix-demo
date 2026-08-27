{{--
    Search box with live suggestions (Phase C).

    ── Why it is a <form> ────────────────────────────────────────────────────
    wire:submit means Enter works, the browser shows a search affordance, and
    the control behaves like a search box rather than a text field that
    happens to have a button next to it. Submitting REDIRECTS to the search
    screen, so the result has a shareable URL and a real history entry.

    ── Accessibility ─────────────────────────────────────────────────────────
    The APG combobox pattern: role="combobox" with aria-expanded,
    aria-controls and aria-autocomplete on the input; the suggestion list is
    a <ul role="listbox"> of <li role="option">. A polite live region reports
    how many suggestions were found, so a screen-reader user learns the list
    changed without having to go looking for it. Escape closes the list, and
    the "clear" button is a real button with a real label.

    Suggestions are <a> elements inside the options: keyboard users reach
    them by tabbing (they are in DOM order, immediately after the input) and
    activate them with Enter, which is the behaviour that works without
    JavaScript-managed roving focus.

    ── Why debounced ─────────────────────────────────────────────────────────
    Every keystroke is a server round trip in Livewire. 300ms is long enough
    that a normal typing burst is one request instead of eight, short enough
    that the list still feels live. The minimum length is enforced server-side
    in SearchBar::suggestions() too — the debounce is an optimisation, never
    the guard.
--}}

@php
    $listId = 'search-suggestions-'.$this->getId();
    $inputId = 'search-input-'.$this->getId();
@endphp

<form wire:submit="submit"
      role="search"
      @class(['relative', 'w-full' => ! $compact, 'w-full sm:w-64 lg:w-80' => $compact])>

    <label for="{{ $inputId }}" class="sr-only">Search for a service</label>

    <div class="flex gap-2">
        <div class="relative flex-1">
            <span aria-hidden="true" class="pointer-events-none absolute inset-y-0 left-3 grid place-items-center">
                <x-icon name="magnifying-glass" class="h-5 w-5 text-slate-400" />
            </span>

            <input id="{{ $inputId }}"
                   type="search"
                   wire:model.live.debounce.300ms="term"
                   wire:keydown.escape="dismiss"
                   autocomplete="off"
                   role="combobox"
                   aria-expanded="{{ $showSuggestions && $suggestions->isNotEmpty() ? 'true' : 'false' }}"
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

    {{-- Loading state. wire:loading is Livewire's own, so it is accurate by
         construction rather than a timer guessing when the request finished. --}}
    <p wire:loading wire:target="term" class="mt-1 text-xs text-slate-500">Searching…</p>

    <p role="status" aria-live="polite" class="sr-only">
        @if ($showSuggestions)
            {{ $suggestions->count() }} {{ \Illuminate\Support\Str::plural('suggestion', $suggestions->count()) }} available
        @endif
    </p>

    @if ($showSuggestions)
        <div class="absolute inset-x-0 top-full z-50 mt-1 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-lg">
            @if ($suggestions->isNotEmpty())
                <ul id="{{ $listId }}" role="listbox" aria-label="Service suggestions" class="max-h-80 divide-y divide-slate-100 overflow-y-auto">
                    @foreach ($suggestions as $suggestion)
                        <li role="option" aria-selected="false">
                            <a href="{{ route('customer.services.show', $suggestion) }}"
                               class="flex min-h-12 items-center justify-between gap-3 px-4 py-2.5 text-left transition hover:bg-slate-50 focus-visible:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-slate-900">
                                {{-- The service NAME is what the customer is
                                     looking for, so it gets the room: it grows
                                     to fill the row and the category label —
                                     context, not the answer — is the one that
                                     truncates first. Without the cap on the
                                     label, a long category name squeezed
                                     names down to "[QA] AC Ge…". --}}
                                <span class="min-w-0 flex-1 truncate text-sm font-medium text-slate-900">{{ $suggestion->name }}</span>
                                @if ($suggestion->category)
                                    <span class="max-w-[40%] shrink-0 truncate text-xs text-slate-500">{{ $suggestion->category->name }}</span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>

                <a href="{{ route('customer.search', ['q' => trim($term)]) }}"
                   class="flex min-h-12 items-center justify-center border-t border-slate-200 bg-slate-50 px-4 text-sm font-medium text-slate-700 transition hover:text-slate-900 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-slate-900">
                    See all results for “{{ $term }}”
                </a>
            @else
                {{-- Honest empty state, in the dropdown itself, rather than
                     silently showing nothing and leaving the customer unsure
                     whether it is still loading. --}}
                <p id="{{ $listId }}" class="px-4 py-4 text-sm text-slate-600">
                    Nothing matched “{{ $term }}”. Try a shorter word, or
                    <a href="{{ route('customer.categories.index') }}" class="font-medium text-slate-900 underline underline-offset-4">browse the categories</a>.
                </p>
            @endif
        </div>
    @endif
</form>
