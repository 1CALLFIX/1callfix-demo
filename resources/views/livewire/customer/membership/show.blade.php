{{-- Membership details + purchase. All figures, benefits, scope lists and terms
     come from the plan / plan_entitlements rows via MembershipPresenter — none
     is written into this template. --}}
<div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 lg:px-8">

    <nav aria-label="Breadcrumb" class="text-sm text-slate-500">
        <a href="{{ route('customer.home') }}" wire:navigate class="hover:text-slate-900">Home</a>
        <span aria-hidden="true" class="mx-1">/</span>
        <span class="text-slate-900">Membership</span>
    </nav>

    <header class="relative mt-4 overflow-hidden rounded-3xl bg-gradient-to-br from-blue-700 via-blue-600 to-blue-800 p-6 text-white shadow-xl shadow-blue-900/20 sm:p-8">
        <div aria-hidden="true" class="pointer-events-none absolute -right-16 -top-20 h-64 w-64 rounded-full bg-white/10 blur-3xl"></div>
        <div class="relative">
            <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $card['name'] }}</h1>
            <p class="mt-3 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <span class="text-4xl font-bold" data-testid="membership-price">{{ $currencySymbol }}{{ number_format($card['price'], 2) }}</span>
                <span class="text-sm text-blue-100" data-testid="membership-validity">valid for {{ $card['validity_label'] }} from activation</span>
            </p>
            @if ($card['address_locked'])
                <p class="mt-3 text-sm text-blue-100">Valid for your registered address only.</p>
            @endif
        </div>
    </header>

    @if ($notice)
        <div role="status" class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ $notice }}</div>
    @endif
    @if ($error)
        <div role="alert" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $error }}</div>
    @endif

    {{-- ------------------------------------------------------------ purchase --}}
    <section aria-labelledby="buy-heading" class="mt-6 rounded-2xl border border-slate-200 p-5">
        <h2 id="buy-heading" class="text-base font-semibold">Get this membership</h2>

        @if ($holds)
            <p class="mt-2 text-sm text-slate-600">You already have this membership.</p>
            <a href="{{ route('customer.membership.account') }}" wire:navigate
               class="mt-3 inline-flex min-h-11 items-center rounded-lg bg-blue-600 px-5 text-sm font-semibold text-white hover:bg-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                View my membership
            </a>
        @elseif (! auth()->check())
            <p class="mt-2 text-sm text-slate-600">Log in or create an account to purchase. Your membership is linked to your account and your registered address.</p>
            <a href="{{ route('customer.login') }}" wire:navigate
               class="mt-3 inline-flex min-h-11 items-center rounded-lg bg-blue-600 px-5 text-sm font-semibold text-white hover:bg-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                Log in to purchase
            </a>
        @else
            @if ($card['address_locked'])
                @if ($addresses->isEmpty())
                    <p class="mt-2 text-sm text-slate-600">This membership is registered to one saved address. Add your address first.</p>
                    <a href="{{ route('customer.addresses') }}" wire:navigate class="mt-2 inline-block text-sm font-medium text-blue-700 hover:underline">Add an address</a>
                @else
                    <label for="registered-address" class="mt-3 block text-sm font-medium text-slate-700">Registered address</label>
                    <select id="registered-address" wire:model="addressId"
                            class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-600">
                        @foreach ($addresses as $address)
                            <option value="{{ $address->id }}">{{ $address->label }} — {{ $address->address_line }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-500">Benefits apply only to bookings made for this address.</p>
                @endif
            @endif

            @if ($card['price'] > 0 && ! $gatewayConfigured)
                <p class="mt-3 text-xs text-slate-500">Online payment isn't available in this environment.</p>
            @endif

            <button type="button" wire:click="purchase" wire:loading.attr="disabled"
                    @disabled($card['address_locked'] && $addresses->isEmpty())
                    class="mt-4 inline-flex min-h-11 items-center rounded-lg bg-blue-600 px-5 text-sm font-semibold text-white shadow-sm shadow-blue-600/25 hover:bg-blue-700 disabled:opacity-60 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                {{ $mine ? 'Complete payment' : 'Buy for '.$currencySymbol.number_format($card['price'], 2) }}
            </button>
        @endif
    </section>

    {{-- ------------------------------------------------------------ benefits --}}
    <section aria-labelledby="benefits-heading" class="mt-8">
        <h2 id="benefits-heading" class="text-lg font-semibold">What's included</h2>

        <ul class="mt-4 space-y-4">
            @foreach ($card['entitlements'] as $benefit)
                @php
                    $includes = $presenter->groups($benefit['includes']);
                    $excludes = $presenter->groups($benefit['excludes']);
                @endphp
                <li class="rounded-2xl border border-slate-200 p-5" data-benefit="{{ $benefit['name'] }}">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <h3 class="text-base font-semibold text-slate-900">{{ $benefit['name'] }}</h3>
                        <span class="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700">
                            @if ($benefit['total_quantity'] !== null)
                                ×{{ $benefit['total_quantity'] }}
                            @else
                                Included
                            @endif
                        </span>
                    </div>

                    @if ($benefit['advertised_value'] !== null && $benefit['total_quantity'])
                        <p class="mt-1 text-sm text-slate-600">
                            Advertised value {{ $currencySymbol }}{{ number_format($benefit['advertised_value']) }} each
                            ({{ $currencySymbol }}{{ number_format($benefit['advertised_value'] * $benefit['total_quantity']) }} in total).
                        </p>
                    @endif
                    @if ($benefit['description'])
                        <p class="mt-2 text-sm text-slate-600">{{ $benefit['description'] }}</p>
                    @endif

                    @if ($includes)
                        <div class="mt-3">
                            <h4 class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Covered</h4>
                            @foreach ($includes as $group => $items)
                                @if ($group !== '')
                                    <p class="mt-2 text-sm font-medium capitalize text-slate-800">{{ $group }}</p>
                                @endif
                                <ul class="mt-1 list-disc space-y-0.5 pl-5 text-sm text-slate-700">
                                    @foreach ($items as $item)<li>{{ $item }}</li>@endforeach
                                </ul>
                            @endforeach
                        </div>
                    @endif

                    @if ($excludes)
                        <div class="mt-3">
                            <h4 class="text-xs font-semibold uppercase tracking-wide text-rose-700">Not included</h4>
                            @foreach ($excludes as $group => $items)
                                @if ($group !== '')
                                    <p class="mt-2 text-sm font-medium capitalize text-slate-800">{{ $group }}</p>
                                @endif
                                <ul class="mt-1 list-disc space-y-0.5 pl-5 text-sm text-slate-700">
                                    @foreach ($items as $item)<li>{{ $item }}</li>@endforeach
                                </ul>
                            @endforeach
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>

    {{-- ------------------------------------------------------- always-charged --}}
    <section aria-labelledby="charges-heading" class="mt-8 rounded-2xl bg-slate-50 p-5">
        <h2 id="charges-heading" class="text-base font-semibold">What is always chargeable</h2>
        <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-slate-700">
            <li>Spare parts and materials are chargeable on every use.</li>
            <li>Additional visits and work outside the covered scope are chargeable.</li>
            <li>The Free Service Visit benefit waives the visiting charge only — the service itself is priced normally.</li>
        </ul>
    </section>

    {{-- ---------------------------------------------------------------- terms --}}
    @if (count($card['terms']))
        <section aria-labelledby="terms-heading" class="mt-8">
            <h2 id="terms-heading" class="text-lg font-semibold">Terms &amp; conditions</h2>
            <ol class="mt-3 list-decimal space-y-1.5 pl-5 text-sm text-slate-700">
                @foreach ($card['terms'] as $term)<li>{{ $term }}</li>@endforeach
            </ol>
        </section>
    @endif

    @if ($gatewayConfigured)
        <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
        <script>
            document.addEventListener('livewire:init', () => {
                Livewire.on('razorpay-open', (e) => {
                    const o = e.order ?? e[0]?.order;
                    if (!o || !window.Razorpay) return;
                    new window.Razorpay({
                        key: o.razorpay_key_id ?? o.key_id,
                        order_id: o.razorpay_order_id,
                        amount: o.amount,
                        currency: o.currency,
                        name: @js(\App\Models\Setting::get('branding.platform_name', '1CallFix')),
                        description: @js($card['name']),
                        // Opening/closing checkout activates nothing — only the verified
                        // webhook does. The account page shows the pending state and polls.
                        handler: () => { window.location.href = @js(route('customer.membership.account')); },
                    }).open();
                });
            });
        </script>
    @endif
</div>
