{{-- Language picker beside the search bar (owner 2026-11-16, the Amazon pattern): a small "EN ▾" button that opens a
     list of every language that is on (Admin → Languages), the current one marked. One form per language: it posts
     to customer.language, exactly as before. Closes on outside click and Escape. --}}
@php
    $cfLanguages = \App\Support\Languages::active();
    $cfLocale = app()->getLocale();
    $cfLanguageText = fn (string $key) => \App\Support\SiteText::get($key);
@endphp
@if (count($cfLanguages) > 1)
    <details class="relative" data-testid="language-switch"
             x-data @click.outside="$el.removeAttribute('open')" @keydown.escape.window="$el.removeAttribute('open')">
        <summary aria-label="{{ $cfLanguageText('language.choose') }}"
                 class="inline-flex min-h-10 cursor-pointer list-none items-center gap-1 whitespace-nowrap rounded-full border border-slate-200 bg-white px-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 sm:px-3 [&::-webkit-details-marker]:hidden">
            <svg class="h-4 w-4 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.6 3.8 5.6 3.8 9S14.5 18.4 12 21c-2.5-2.6-3.8-5.6-3.8-9S9.5 5.6 12 3z"/></svg>
            <span lang="{{ $cfLocale }}" class="uppercase">{{ $cfLocale }}</span>
            <svg class="h-4 w-4 text-slate-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
        </summary>
        <div class="absolute end-0 z-50 mt-2 w-60 rounded-xl border border-slate-200 bg-white p-2 shadow-xl shadow-slate-900/10">
            <p class="px-3 pb-1 pt-1 text-xs font-semibold uppercase tracking-wide text-slate-400">{{ $cfLanguageText('language.choose') }}</p>
            @foreach ($cfLanguages as $code => $lang)
                <form method="POST" action="{{ route('customer.language', $code) }}">
                    @csrf
                    <button type="submit" lang="{{ $code }}" @if ($lang['rtl']) dir="rtl" @endif
                            @class(['flex min-h-11 w-full items-center gap-3 rounded-lg px-3 py-2 text-start text-sm hover:bg-slate-50', 'font-semibold text-blue-700' => $code === $cfLocale, 'text-slate-700' => $code !== $cfLocale])>
                        <span aria-hidden="true" @class(['grid h-4 w-4 shrink-0 place-items-center rounded-full border', 'border-blue-600' => $code === $cfLocale, 'border-slate-300' => $code !== $cfLocale])>
                            @if ($code === $cfLocale)<span class="h-2 w-2 rounded-full bg-blue-600"></span>@endif
                        </span>
                        <span>{{ $lang['name'] }}</span>
                        <span class="ms-auto text-xs uppercase text-slate-400">{{ $code }}</span>
                    </button>
                </form>
            @endforeach
        </div>
    </details>
@endif
