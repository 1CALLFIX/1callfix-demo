@php $t = fn (string $key, array $r = []) => \App\Support\SiteText::get($key, $r); @endphp
{{-- Account → Saved (owner 2026-11-07): everything liked with ♡, newest first. --}}
<div class="mb-bottom-nav">
    <div class="mx-auto max-w-3xl px-4 py-8 sm:px-6">
        <h1 class="text-2xl font-bold tracking-tight text-slate-900">{{ $t('saved.title') }}</h1>
        <p class="mt-1 text-sm text-slate-600">{{ $t('saved.intro') }}</p>

        @if ($items->isEmpty())
            <div class="mt-8 rounded-3xl border border-dashed border-slate-300 bg-white p-8 text-center" data-testid="saved-empty">
                <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-rose-50 text-rose-500">
                    <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 20.5s-7.5-4.6-9.3-9.2C1.5 8.2 3.4 4.5 7 4.5c2 0 3.6 1.1 5 3 1.4-1.9 3-3 5-3 3.6 0 5.5 3.7 4.3 6.8-1.8 4.6-9.3 9.2-9.3 9.2z"/></svg>
                </span>
                <p class="mt-3 font-semibold text-slate-900">{{ $t('saved.empty') }}</p>
                <p class="mt-1 text-sm text-slate-600">{{ $t('saved.empty_hint') }}</p>
                <a href="{{ route('customer.home') }}" wire:navigate class="mt-4 inline-flex min-h-11 items-center rounded-full bg-blue-600 px-5 text-sm font-semibold text-white hover:bg-blue-700">{{ $t('saved.browse') }}</a>
            </div>
        @else
            <ul class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3">
                @foreach ($items as $item)
                    <li class="relative overflow-hidden rounded-2xl border border-slate-200 bg-white" wire:key="saved-{{ $item->id }}" data-testid="saved-item">
                        <a href="{{ $item->url }}" class="block">
                            <span class="flex aspect-square w-full items-center justify-center bg-slate-100 text-3xl font-bold text-slate-300">
                                @if ($item->image_url)
                                    <img src="{{ $item->image_url }}" alt="" loading="lazy" class="h-full w-full object-cover">
                                @else
                                    {{ mb_substr($item->title, 0, 1) }}
                                @endif
                            </span>
                            <span class="line-clamp-2 p-3 text-sm font-semibold text-slate-900">{{ $item->title }}</span>
                        </a>
                        <button type="button" wire:click="remove({{ $item->id }})" aria-label="{{ $t('saved.remove', ['name' => $item->title]) }}"
                                class="absolute right-2 top-2 flex h-9 w-9 items-center justify-center rounded-full bg-white/95 text-rose-500 shadow-md ring-1 ring-black/5">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 20.5s-7.5-4.6-9.3-9.2C1.5 8.2 3.4 4.5 7 4.5c2 0 3.6 1.1 5 3 1.4-1.9 3-3 5-3 3.6 0 5.5 3.7 4.3 6.8-1.8 4.6-9.3 9.2-9.3 9.2z"/></svg>
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
