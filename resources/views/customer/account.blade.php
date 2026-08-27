@php
    $user = auth()->user();
@endphp

{{--
    Account landing page (Phase B).

    Shows ONLY what the authenticated session already knows: the customer's
    own name and phone. Wallet balance, membership status, loyalty points,
    order history and the saved address book are Phase D/E — every one of
    them is a server-authoritative figure, and showing a placeholder number
    in its place would be worse than showing nothing. Each is listed below
    as an explicit, honest "not yet available" row instead.
--}}
<x-layouts.customer title="Your account">
    <div class="mx-auto max-w-3xl px-4 py-12 sm:px-6 sm:py-16 lg:px-8">

        <header class="flex items-center gap-4">
            <span aria-hidden="true"
                  class="grid h-14 w-14 shrink-0 place-items-center rounded-full bg-slate-100 text-lg font-semibold text-slate-700">
                {{ \Illuminate\Support\Str::of($user->name)->substr(0, 1)->upper() }}
            </span>
            <div class="min-w-0">
                <h1 class="truncate text-2xl font-bold tracking-tight text-slate-900">{{ $user->name }}</h1>
                <p class="mt-0.5 text-sm text-slate-600">{{ $user->phone }}</p>
            </div>
        </header>

        <section aria-labelledby="account-sections-heading" class="mt-10">
            <h2 id="account-sections-heading" class="text-sm font-semibold uppercase tracking-wide text-slate-500">
                Coming to your account
            </h2>
            <ul class="mt-4 divide-y divide-slate-200 border-y border-slate-200">
                @foreach ([
                    ['Your bookings', 'Track live jobs and revisit past ones.'],
                    ['Saved addresses', 'Keep the places you book for most.'],
                    ['Wallet', 'Balance, top-ups and your transaction history.'],
                    ['Membership', 'Plan benefits and what you have used.'],
                ] as [$sectionTitle, $sectionBody])
                    <li class="flex items-center justify-between gap-4 py-4">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-slate-900">{{ $sectionTitle }}</p>
                            <p class="mt-0.5 text-sm text-slate-600">{{ $sectionBody }}</p>
                        </div>
                        {{-- Status is spelled out in words, never conveyed by
                             colour or position alone (WCAG 2.1 AA 1.4.1). --}}
                        <span class="shrink-0 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600">
                            Not yet available
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>

        <div class="mt-10">
            <form method="POST" action="{{ route('customer.logout') }}">
                @csrf
                <button type="submit"
                        class="inline-flex min-h-11 items-center justify-center rounded-lg border border-slate-300 bg-white px-5 py-2.5 text-sm font-semibold text-slate-800 transition hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                    Log out
                </button>
            </form>
        </div>
    </div>
</x-layouts.customer>
