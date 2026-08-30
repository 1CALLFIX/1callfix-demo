{{--
    Services cart. Lines are grouped into "visits" by subcategory
    (ServiceCartService::groupedForUser) — services one professional can do
    in a single visit sit together; a different trade is its own visit. The
    estimate is advisory; the checkout re-prices every line server-side.
--}}
<div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 lg:px-8 mb-bottom-nav">

    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Your cart</h1>

    @if ($groups->isEmpty())
        <div class="mt-8 rounded-xl border border-slate-200 p-8 text-center">
            <p class="text-sm text-slate-600">Your cart is empty.</p>
            <a href="{{ route('customer.services.index') }}" wire:navigate
               class="mt-4 inline-flex min-h-11 items-center rounded-lg bg-slate-900 px-5 text-sm font-semibold text-white transition hover:bg-slate-800">
                Browse services
            </a>
        </div>
    @else
        <p class="mt-2 text-sm text-slate-600">
            Each box below is one visit — one professional handles everything in it where possible.
            Different trades are dispatched as separate visits.
        </p>

        <div class="mt-6 space-y-6">
            @foreach ($groups as $group)
                <section class="rounded-xl border border-slate-200">
                    <header class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                        <h2 class="text-sm font-semibold text-slate-900">{{ $group['label'] }}</h2>
                        <span class="text-xs text-slate-500">
                            {{ $group['item_count'] }} {{ \Illuminate\Support\Str::plural('service', $group['item_count']) }} · one visit
                        </span>
                    </header>

                    <ul class="divide-y divide-slate-100">
                        @foreach ($group['items'] as $item)
                            <li class="p-4" wire:key="cart-item-{{ $item->id }}">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <a href="{{ route('customer.services.show', $item->service) }}" wire:navigate
                                           class="block truncate text-sm font-medium text-slate-900 hover:underline">
                                            {{ $item->service->name }}
                                        </a>
                                        <p class="mt-0.5 text-xs text-slate-500">{{ $item->category?->name }}</p>
                                        @if ($item->customer_note)
                                            <p class="mt-1 text-xs text-slate-500">Note: {{ $item->customer_note }}</p>
                                        @endif
                                    </div>

                                    <div class="shrink-0 text-right">
                                        <span class="block text-sm font-semibold text-slate-900">
                                            {{ $currencySymbol }}{{ number_format((float) ($item->unit_price_estimate ?? 0) * $item->quantity, 2) }}
                                        </span>
                                        <span class="block text-[11px] text-slate-400">est.</span>
                                    </div>
                                </div>

                                <div class="mt-3 flex flex-wrap items-end gap-4">
                                    {{-- Quantity --}}
                                    <div>
                                        <span class="block text-xs font-medium text-slate-600">Quantity</span>
                                        <div class="mt-1 inline-flex items-center rounded-lg border border-slate-300">
                                            <button type="button" wire:click="changeQty({{ $item->id }}, -1)"
                                                    class="grid h-9 w-9 place-items-center text-slate-600 hover:text-slate-900" aria-label="Decrease quantity">−</button>
                                            <span class="w-8 text-center text-sm font-medium text-slate-900">{{ $item->quantity }}</span>
                                            <button type="button" wire:click="changeQty({{ $item->id }}, 1)"
                                                    class="grid h-9 w-9 place-items-center text-slate-600 hover:text-slate-900" aria-label="Increase quantity">+</button>
                                        </div>
                                    </div>

                                    {{-- Preferred time --}}
                                    <div class="flex-1 min-w-[12rem]">
                                        <label for="sched-{{ $item->id }}" class="block text-xs font-medium text-slate-600">Preferred time (optional)</label>
                                        <input type="datetime-local" id="sched-{{ $item->id }}"
                                               wire:model.blur="schedules.{{ $item->id }}"
                                               class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-slate-900 focus:outline focus:outline-2 focus:outline-offset-0 focus:outline-slate-900">
                                        @if (! empty($scheduleErrors[$item->id]))
                                            <p role="alert" class="mt-1 text-xs text-red-600">{{ $scheduleErrors[$item->id] }}</p>
                                        @endif
                                    </div>

                                    <button type="button" wire:click="removeItem({{ $item->id }})"
                                            class="min-h-9 text-xs font-medium text-slate-500 underline underline-offset-2 hover:text-red-600">
                                        Remove
                                    </button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>

        <div class="mt-8 rounded-xl border border-slate-200 p-4">
            <div class="flex items-baseline justify-between">
                <span class="text-sm font-medium text-slate-700">Estimated total</span>
                <span class="text-xl font-bold text-slate-900">{{ $currencySymbol }}{{ number_format($estimateTotal, 2) }}</span>
            </div>
            <p class="mt-1 text-xs text-slate-500">Estimate only. Your final price is confirmed at checkout.</p>

            <button type="button" wire:click="proceed"
                    @disabled(! empty($scheduleErrors))
                    class="mt-4 flex min-h-12 w-full items-center justify-center rounded-lg bg-slate-900 px-6 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:opacity-50">
                Proceed to checkout
            </button>
        </div>
    @endif
</div>
