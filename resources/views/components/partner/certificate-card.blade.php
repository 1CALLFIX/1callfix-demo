@props(['app'])
{{-- Owner 2026-11-26: the way into My certificate from each business partner app's Home (Shop, Hotel, Rental, Events),
     the same card in every app. Shown once at least one of the partner's businesses is approved and switched on. --}}
@php
    $certificates = app(\App\Services\Partners\CertificateService::class);
    $ready = auth()->check() && $certificates->businesses(auth()->user(), $app)->contains(fn ($b) => $certificates->state($b) === 'ready');
@endphp
@if ($ready)
    <a href="{{ route($app.'.certificate') }}" wire:navigate data-testid="certificate-card"
       {{ $attributes->class(['flex min-h-11 items-center gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4']) }}>
        <span class="text-3xl" aria-hidden="true">🏅</span>
        <span class="min-w-0 flex-1">
            <span class="block font-semibold text-slate-900">Your Verified Partner certificate</span>
            <span class="block text-sm text-slate-600">Print it and show it at your counter, with a “Scan to order” sticker for your door.</span>
        </span>
        <span class="text-xl font-bold text-amber-700" aria-hidden="true">→</span>
    </a>
@endif
