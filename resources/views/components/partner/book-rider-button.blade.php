{{-- Restaurant delivery service (owner 2026-11-12): a restaurant or shop books one of our riders for its OWN order
     (phone / WhatsApp). Opens Send a parcel with the shop as pickup, food, paid from the 1CallFix balance. Shown only
     while Parcel is on somewhere and the shop has a map point. --}}
@php
    $show = app(\App\Services\ModuleActivationService::class)->isActiveAnywhere(\App\Support\Modules::PARCEL)
        && \App\Models\Store::whereHas('provider', fn ($q) => $q->where('user_id', auth()->id()))->whereNotNull('lat')->whereNotNull('lng')->exists();
@endphp
@if ($show)
    <a href="{{ route('customer.parcels.create', ['from' => 'store']) }}" {{ $attributes->merge(['class' => 'flex min-h-12 items-center justify-between gap-3 rounded-2xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900 hover:bg-emerald-100']) }} data-testid="book-rider">
        <span>🛵 Book a rider for your own order <span class="block text-xs font-normal">Phone or WhatsApp order? We deliver it.</span></span>
        <span aria-hidden="true">→</span>
    </a>
@endif
