{{--
    Services cart. Lines are grouped into "visits" by subcategory
    (ServiceCartService::groupedForUser) — services one professional can do
    in a single visit sit together; a different trade is its own visit. The
    estimate is advisory; the checkout re-prices every line server-side.

    One cart (owner 2026-10-30): services on top, then the shop bag (one box per shop, each its own checkout), so the
    header has one cart icon. Every word comes from SiteText (Super Admin, English + Telugu).
--}}
@php $t = fn (string $key, array $r = []) => \App\Support\SiteText::get($key, $r); @endphp
<div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 lg:px-8 mb-bottom-nav">

    <h1 class="text-2xl font-bold tracking-tight text-slate-900">{{ $t('cart.title') }}</h1>

    @if ($groups->isEmpty() && $shopLines === 0)
        <div class="mt-8 rounded-xl border border-slate-200 p-8 text-center" data-testid="cart-empty">
            <p class="text-sm font-semibold text-slate-700">{{ $t('cart.empty') }}</p>
            <p class="mt-1 text-sm text-slate-500">{{ $t('cart.empty_hint') }}</p>
            <div class="mt-4 flex flex-wrap justify-center gap-3">
                <a href="{{ route('customer.services.index') }}" wire:navigate
                   class="inline-flex min-h-11 items-center rounded-lg bg-blue-600 px-5 text-sm font-semibold text-white transition hover:bg-blue-700">
                    {{ $t('cart.browse_services') }}
                </a>
                @if ($shopUrl)
                    <a href="{{ $shopUrl }}" wire:navigate
                       class="inline-flex min-h-11 items-center rounded-lg border border-blue-600 px-5 text-sm font-semibold text-blue-700 transition hover:bg-blue-50">
                        {{ $t('cart.browse_shop') }}
                    </a>
                @endif
            </div>
        </div>
    @endif

    @if ($groups->isNotEmpty())
        <h2 class="mt-6 flex items-center gap-2 text-lg font-bold text-slate-900" data-testid="cart-services">
            <x-icon name="wrench" class="h-5 w-5 text-blue-600" /> {{ $t('cart.services_heading') }}
        </h2>
        <p class="mt-1 text-sm text-slate-600">{{ $t('cart.services_intro') }}</p>

        <div class="mt-6 space-y-6">
            @foreach ($groups as $group)
                <section class="rounded-xl border border-slate-200">
                    <header class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                        <h2 class="text-sm font-semibold text-slate-900">{{ $group['label'] }}</h2>
                        <span class="text-xs text-slate-500">
                            {{ $group['item_count'] === 1 ? $t('cart.visit_one') : $t('cart.visit_many', ['count' => $group['item_count']]) }}
                        </span>
                    </header>

                    <ul class="divide-y divide-slate-100">
                        @foreach ($group['items'] as $item)
                            <li class="p-4" wire:key="cart-item-{{ $item->id }}">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <a href="{{ \App\Support\Seo\PublicUrl::service($item->service) }}" wire:navigate
                                           class="block truncate text-sm font-medium text-slate-900 hover:underline">
                                            {{ $item->service->name }}
                                        </a>
                                        <p class="mt-0.5 text-xs text-slate-500">{{ $item->category?->name }}</p>
                                        @if ($item->customer_note)
                                            <p class="mt-1 text-xs text-slate-500">{{ $t('cart.note', ['note' => $item->customer_note]) }}</p>
                                        @endif
                                    </div>

                                    <div class="shrink-0 text-right">
                                        <span class="block text-sm font-semibold text-slate-900">
                                            {{ $currencySymbol }}{{ number_format((float) ($item->unit_price_estimate ?? 0) * $item->quantity, 2) }}
                                        </span>
                                        <span class="block text-[11px] text-slate-400">{{ $t('cart.estimate_short') }}</span>
                                    </div>
                                </div>

                                <div class="mt-3 flex flex-wrap items-end gap-4">
                                    {{-- Quantity --}}
                                    <div>
                                        <span class="block text-xs font-medium text-slate-600">{{ $t('cart.quantity') }}</span>
                                        <div class="mt-1 inline-flex items-center rounded-lg border border-slate-300">
                                            <button type="button" wire:click="changeQty({{ $item->id }}, -1)"
                                                    class="grid h-9 w-9 place-items-center text-slate-600 hover:text-slate-900" aria-label="{{ $t('cart.one_less') }}">−</button>
                                            <span class="w-8 text-center text-sm font-medium text-slate-900">{{ $item->quantity }}</span>
                                            <button type="button" wire:click="changeQty({{ $item->id }}, 1)"
                                                    class="grid h-9 w-9 place-items-center text-slate-600 hover:text-slate-900" aria-label="{{ $t('cart.one_more') }}">+</button>
                                        </div>
                                        @if (! empty($qtyErrors[$item->id]))
                                            <p role="alert" class="mt-1 text-xs text-red-600">{{ $qtyErrors[$item->id] }}</p>
                                        @endif
                                    </div>

                                    {{-- Preferred time --}}
                                    <div class="flex-1 min-w-[12rem]">
                                        <label for="sched-{{ $item->id }}" class="block text-xs font-medium text-slate-600">{{ $t('cart.preferred_time') }}</label>
                                        <input type="datetime-local" id="sched-{{ $item->id }}"
                                               wire:model.blur="schedules.{{ $item->id }}"
                                               class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-blue-500 focus:outline focus:outline-2 focus:outline-offset-0 focus:outline-blue-600">
                                        @if (! empty($scheduleErrors[$item->id]))
                                            <p role="alert" class="mt-1 text-xs text-red-600">{{ $scheduleErrors[$item->id] }}</p>
                                        @endif
                                    </div>

                                    <button type="button" wire:click="removeItem({{ $item->id }})"
                                            class="min-h-9 text-xs font-medium text-slate-500 underline underline-offset-2 hover:text-red-600">
                                        {{ $t('cart.remove') }}
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
                <span class="text-sm font-medium text-slate-700">{{ $t('cart.estimated_total') }}</span>
                <span class="text-xl font-bold text-slate-900">{{ $currencySymbol }}{{ number_format($estimateTotal, 2) }}</span>
            </div>
            <p class="mt-1 text-xs text-slate-500">{{ $t('cart.estimate_note') }}</p>
            @if ($cashNote)
                <p class="mt-1 text-xs font-medium text-slate-700">{{ $cashNote }}</p>
            @endif

            <x-customer.coupon-box :coupon="$coupon" :currency-symbol="$currencySymbol" class="mt-4" />

            <button type="button" wire:click="proceed"
                    @disabled(! empty($scheduleErrors))
                    class="mt-4 flex min-h-12 w-full items-center justify-center rounded-lg bg-blue-600 px-6 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:opacity-50">
                {{ $t('cart.book_services') }}
            </button>
        </div>
    @endif

    @if ($shopLines > 0)
        <livewire:customer.shop.bag :embedded="true" />
    @endif
</div>
