{{-- Popularity line under an item's photo (owner 2026-11-21, Amazon / OLX pattern): "🔥 50+ booked in the past month",
     "👁 19 people viewed this in the past hour". Real counts only, shown once Super Admin sets the minimums on
     Admin → Live Visitors (App\Services\Visitors\Popularity). Uses only classes already in the built CSS (no build). --}}
@props(['item'])
@php
    $popularityPath = \Livewire\Livewire::isLivewireRequest() ? \Livewire\Livewire::originalPath() : request()->path();
    $popularityLines = app(\App\Services\Visitors\Popularity::class)->lines((string) $item, (string) $popularityPath);
@endphp
@if ($popularityLines !== [])
    <div {{ $attributes->merge(['class' => 'mt-3 flex flex-wrap gap-2']) }} data-popularity>
        @foreach ($popularityLines as $line)
            <span @class(['inline-flex items-center gap-1 rounded-full px-3 py-1 text-sm font-semibold',
                    'bg-orange-50 text-orange-800 ring-1 ring-orange-200' => $line['kind'] === 'orders',
                    'bg-slate-800 text-white' => $line['kind'] === 'views'])
                  data-popularity-{{ $line['kind'] }}><span aria-hidden="true">{{ $line['icon'] }}</span> {{ $line['text'] }}</span>
        @endforeach
    </div>
@endif
