{{-- The signed-in customer's own memberships. Read straight from subscriptions /
     entitlement_balances / usage_ledger through MembershipPresenter. Polls only
     while a payment is still awaiting the Razorpay webhook. --}}
<div class="mx-auto max-w-3xl px-4 py-6 sm:px-6 lg:px-8" @if ($hasPending) wire:poll.5s @endif>
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold tracking-tight">My membership</h1>
        <a href="{{ route('customer.account') }}" wire:navigate class="text-sm text-slate-500 hover:text-slate-900">Account</a>
    </div>

    @if ($notice)
        <div role="status" class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ $notice }}</div>
    @endif
    @if ($error)
        <div role="alert" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $error }}</div>
    @endif

    @forelse ($subscriptions as $row)
        @php($sub = $row['model'])
        <article class="mt-6 rounded-2xl border border-slate-200 p-5" data-subscription="{{ $sub->id }}">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <h2 class="text-lg font-semibold">
                    <a href="{{ route('customer.membership.show', $sub->plan) }}" wire:navigate class="hover:underline">{{ $sub->plan->name }}</a>
                </h2>
                {{-- Status is spelled out in words, never conveyed by colour alone. --}}
                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700" data-testid="membership-status">{{ $row['status_label'] }}</span>
            </div>

            @if ($sub->status === 'pending_payment')
                <p class="mt-2 text-sm text-slate-600">We're waiting for your payment to be confirmed. This page updates on its own.</p>
            @endif

            <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                <div>
                    <dt class="text-slate-500">Activated</dt>
                    <dd class="font-medium" data-testid="membership-activated">{{ $row['activated'] ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Valid until</dt>
                    <dd class="font-medium" data-testid="membership-expiry">{{ $row['ends'] ?? '—' }}@if ($row['days_left'] !== null) <span class="font-normal text-slate-500">({{ $row['days_left'] }} days left)</span>@endif</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Validity</dt>
                    <dd class="font-medium">{{ $sub->plan->validityLabel() }}</dd>
                </div>
            </dl>

            @if ($sub->registeredAddress)
                <p class="mt-3 text-sm text-slate-600">Registered address: <span class="font-medium text-slate-800">{{ $sub->registeredAddress->label }} — {{ $sub->registeredAddress->address_line }}</span></p>
            @elseif ($sub->plan->isAddressLocked())
                <p class="mt-3 text-sm text-amber-700">No registered address is set on this membership.</p>
            @endif

            {{-- --------------------------------------------- remaining benefits --}}
            @if ($row['balances']->isNotEmpty())
                <h3 class="mt-5 text-sm font-semibold text-slate-700">Your benefits</h3>
                <ul class="mt-2 divide-y divide-slate-100 rounded-xl border border-slate-200">
                    @foreach ($row['balances'] as $b)
                        <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm" data-balance="{{ $b['name'] }}">
                            <div class="min-w-0">
                                <p class="font-medium text-slate-900">{{ $b['name'] }}</p>
                                @if (! empty($row['spent_on'][$b['entitlement_id']]))
                                    <p class="text-xs text-slate-500">Used for: {{ implode(', ', array_map('ucfirst', $row['spent_on'][$b['entitlement_id']])) }}</p>
                                @elseif ($b['choices'])
                                    <p class="text-xs text-slate-500">Choose one: {{ implode(' / ', array_map('ucfirst', $b['choices'])) }}</p>
                                @endif
                            </div>
                            <span class="shrink-0 font-semibold text-slate-900" data-testid="balance-remaining">
                                @if ($b['limited'])
                                    {{ $b['remaining'] }} / {{ $b['total'] }} <span class="font-normal text-slate-500">remaining</span>
                                @else
                                    Included
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif

            {{-- -------------------------------------------------- usage history --}}
            <h3 class="mt-5 text-sm font-semibold text-slate-700">Usage history</h3>
            <ul class="mt-2 divide-y divide-slate-100 rounded-xl border border-slate-200" data-testid="usage-history">
                @forelse ($row['usage'] as $u)
                    <li class="px-4 py-3 text-sm">
                        <p class="text-slate-800">
                            {{ $u['entitlement_name'] }}
                            <span class="text-slate-500">
                                @switch ($u['event_type'])
                                    @case('consume') — used @break
                                    @case('reverse') — restored @break
                                    @case('adjust') — adjusted by support @break
                                    @case('expire') — expired @break
                                    @default — {{ $u['event_type'] }}
                                @endswitch
                                @if ($u['redeemed_category']) ({{ ucfirst($u['redeemed_category']) }})@endif
                                @if ($u['booking_code']) · booking {{ $u['booking_code'] }}@endif
                            </span>
                        </p>
                        <p class="text-xs text-slate-400">{{ $u['at'] }}</p>
                    </li>
                @empty
                    <li class="px-4 py-5 text-center text-sm text-slate-500">No benefits used yet.</li>
                @endforelse
            </ul>

            {{-- ------------------------------------------------------- actions --}}
            <div class="mt-5 flex flex-wrap gap-3">
                @if ($row['can_pay'])
                    <button type="button" wire:click="renew({{ $sub->id }})" wire:loading.attr="disabled"
                            class="inline-flex min-h-11 items-center rounded-lg bg-blue-600 px-5 text-sm font-semibold text-white hover:bg-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                        {{ $sub->status === 'pending_payment' || $sub->status === 'failed' ? 'Complete payment' : 'Renew membership' }}
                    </button>
                @endif
                @if ($row['can_cancel'])
                    <button type="button" wire:click="cancel({{ $sub->id }})"
                            wire:confirm="Stop this membership from renewing? Your benefits stay available until the current period ends."
                            class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-5 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                        Cancel membership
                    </button>
                @endif
            </div>
        </article>
    @empty
        <div class="mt-8 rounded-2xl border border-dashed border-slate-300 p-8 text-center">
            <p class="text-sm text-slate-600">You don't have a membership yet.</p>
            @if ($offer)
                <a href="{{ route('customer.membership.show', $offer) }}" wire:navigate
                   class="mt-3 inline-flex min-h-11 items-center rounded-lg bg-blue-600 px-5 text-sm font-semibold text-white hover:bg-blue-700">
                    See {{ $offer->name }}
                </a>
            @endif
        </div>
    @endforelse

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
                        description: 'Membership',
                        handler: () => window.location.reload(),
                    }).open();
                });
            });
        </script>
    @endif
</div>
