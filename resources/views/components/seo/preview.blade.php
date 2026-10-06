@props([
    'title' => '',
    'description' => '',
    'url' => '',
    'titleMax' => \App\Services\Seo\SeoSettings::TITLE_RECOMMENDED,
    'descriptionMax' => \App\Services\Seo\SeoSettings::DESCRIPTION_RECOMMENDED,
])

{{-- Search-result preview and length counters. Server-rendered from the live Livewire values, so it always shows what
     the page will actually emit. Counters turn amber past the length search engines usually show. --}}
@php
    $titleLen = mb_strlen((string) $title);
    $descLen = mb_strlen((string) $description);
@endphp
<div {{ $attributes->merge(['class' => 'rounded border border-gray-200 bg-white p-3']) }} data-testid="seo-preview">
    <p class="text-[11px] uppercase tracking-wide text-gray-400 mb-1">Search preview</p>
    <p class="truncate text-xs text-green-700">{{ $url }}</p>
    <p class="truncate text-base text-blue-700">{{ \Illuminate\Support\Str::limit((string) $title, $titleMax + 10, '…') ?: '(no title)' }}</p>
    <p class="text-sm text-gray-600">{{ \Illuminate\Support\Str::limit((string) $description, $descriptionMax + 20, '…') ?: '(no description — the site default is used)' }}</p>
    <p class="mt-2 text-xs">
        <span @class(['text-amber-600' => $titleLen > $titleMax, 'text-gray-500' => $titleLen <= $titleMax]) data-testid="title-count">Title {{ $titleLen }}/{{ $titleMax }}</span>
        ·
        <span @class(['text-amber-600' => $descLen > $descriptionMax, 'text-gray-500' => $descLen <= $descriptionMax]) data-testid="description-count">Description {{ $descLen }}/{{ $descriptionMax }}</span>
    </p>
</div>
