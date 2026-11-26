{{-- Owner 2026-11-26: the page a 1CallFix ID card's QR opens. A customer at the door sees at a glance whether this
     person is a genuine, active 1CallFix partner right now. Never indexed (one person's page, not catalogue). --}}
@php
    $look = [
        'active' => ['bg-emerald-600', '✅', 'Active 1CallFix partner', 'This person works with 1CallFix right now. Check the photo matches.'],
        'expired' => ['bg-amber-500', '⚠️', 'Card expired', 'This card is past its date. Ask the partner to show the new card in the 1CallFix app.'],
        'not_active' => ['bg-rose-600', '❌', 'Not active', 'This person is not working with 1CallFix right now. Do not let them start work; book through the app instead.'],
        'invalid' => ['bg-rose-600', '❌', 'Not a valid card', 'This card is not valid any more (or never was). Do not let them start work; book through the app instead.'],
    ][$status];
@endphp
<x-layouts.customer title="Check a 1CallFix ID card">
    <div class="mx-auto max-w-md px-4 py-6">
        <div class="rounded-2xl {{ $look[0] }} p-5 text-center text-white" data-testid="verify-status" data-status="{{ $status }}">
            <div class="text-4xl" aria-hidden="true">{{ $look[1] }}</div>
            <h1 class="mt-2 text-2xl font-bold">{{ $look[2] }}</h1>
            <p class="mt-1 text-sm">{{ $look[3] }}</p>
        </div>

        @if ($c)
            <div class="mt-5 rounded-2xl border border-slate-200 bg-white p-4 text-center">
                @if ($c['photo'])
                    <img src="{{ $c['photo'] }}" alt="Photo of {{ $c['name'] }}" class="mx-auto h-40 w-32 rounded-xl object-cover">
                @endif
                <p class="mt-3 text-xl font-bold">{{ $c['name'] }}</p>
                <p class="text-sm font-semibold text-blue-700">{{ $c['work'] }}</p>
                <p class="mt-2 text-sm text-slate-600">ID {{ $c['code'] }} · {{ $c['city'] }}</p>
                <p class="text-sm text-slate-600">Card valid till {{ $c['valid_until'] }}</p>
            </div>
        @endif

        <p class="mt-5 text-center text-sm text-slate-600">
            1CallFix never asks you to pay a partner outside the app.
            <a href="{{ route('customer.home') }}" class="font-semibold text-blue-700">Open 1CallFix</a>
        </p>
    </div>
</x-layouts.customer>
