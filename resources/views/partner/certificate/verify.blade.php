{{-- Owner 2026-11-26: the page a "Verified 1CallFix Partner" certificate's QR opens (/verify/{token}, the same check as
     the ID card). A customer sees at a glance whether this business is a genuine, active 1CallFix partner right now,
     and can open its page on 1CallFix. Never indexed (one business's check, not catalogue). --}}
@php
    $look = [
        'active' => ['bg-emerald-600', '✅', 'Verified 1CallFix partner', 'This business works with 1CallFix right now.'],
        'expired' => ['bg-amber-500', '⚠️', 'Certificate expired', 'This certificate is past its date. The business can print this year\'s one from the 1CallFix app.'],
        'not_active' => ['bg-rose-600', '❌', 'Not active', 'This business is not working with 1CallFix right now. Order or book only through the 1CallFix app.'],
    ][$status];
@endphp
<x-layouts.customer title="Check a 1CallFix certificate">
    <div class="mx-auto max-w-md px-4 py-6">
        <div class="rounded-2xl {{ $look[0] }} p-5 text-center text-white" data-testid="verify-status" data-status="{{ $status }}">
            <div class="text-4xl" aria-hidden="true">{{ $look[1] }}</div>
            <h1 class="mt-2 text-2xl font-bold">{{ $look[2] }}</h1>
            <p class="mt-1 text-sm">{{ $look[3] }}</p>
        </div>

        <div class="mt-5 rounded-2xl border border-slate-200 bg-white p-4 text-center">
            <p class="text-xl font-bold">{{ $c['name'] }}</p>
            <p class="text-sm font-semibold" style="color: {{ $c['colour'] }}">{{ $c['label'] }}@if ($c['city']) · {{ $c['city'] }}@endif</p>
            <p class="mt-2 text-sm text-slate-600">ID {{ $c['code'] }} · verified since {{ $c['since'] }}</p>
            <p class="text-sm text-slate-600">Certificate valid till {{ $c['valid_until'] }}</p>
            @if ($status === 'active' && $c['public_url'])
                <a href="{{ $c['public_url'] }}" class="mt-4 inline-flex min-h-11 items-center rounded-xl bg-blue-600 px-5 text-sm font-semibold text-white">See {{ $c['name'] }} on 1CallFix</a>
            @endif
        </div>

        <p class="mt-5 text-center text-sm text-slate-600">
            1CallFix never asks you to pay a partner outside the app.
            <a href="{{ route('customer.home') }}" class="font-semibold text-blue-700">Open 1CallFix</a>
        </p>
    </div>
</x-layouts.customer>
