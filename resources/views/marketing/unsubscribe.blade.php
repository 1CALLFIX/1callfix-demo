{{-- Unsubscribe from app invitation emails (owner 2026-11-21). Private utility page: no Share button, not indexed. --}}
@php($t = fn (string $key) => \App\Support\SiteText::get($key))
<x-layouts.customer :title="$t('unsubscribe.title')">
    <div class="mx-auto max-w-md px-4 py-16 text-center">
        <h1 class="mb-4 text-2xl font-bold text-slate-900">{{ $t('unsubscribe.title') }}</h1>
        @if ($done)
            <p class="mb-6 rounded-2xl bg-emerald-50 px-4 py-3 text-emerald-800" data-unsubscribed>{{ $t('unsubscribe.done') }}</p>
        @else
            <p class="mb-6 text-slate-700">{{ $t('unsubscribe.ask') }}</p>
            <form method="POST" action="{{ route('marketing.unsubscribe.store', $token) }}">
                @csrf
                <button type="submit" class="min-h-11 rounded-xl bg-slate-900 px-6 font-semibold text-white">{{ $t('unsubscribe.button') }}</button>
            </form>
        @endif
        <a href="{{ url('/') }}" class="mt-6 inline-block text-sm font-semibold text-blue-700">{{ $t('unsubscribe.home') }}</a>
    </div>
</x-layouts.customer>
