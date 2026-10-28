<div class="mb-bottom-nav">
    <div class="mx-auto max-w-3xl px-4 py-6 sm:px-6">
        <h1 class="text-2xl font-bold tracking-tight">Your bag</h1>

        @if ($message)
            <p role="status" class="mt-3 rounded-lg bg-amber-50 px-4 py-2.5 text-sm text-amber-900">{{ $message }}</p>
        @endif

        @forelse ($groups as $g)
            @php $store = $g['store']; $citySlug = $store->franchise?->city?->slug; @endphp
            <section class="mt-5 rounded-3xl border border-slate-200 bg-white p-4 sm:p-5" wire:key="bag-{{ $store->id }}">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="font-bold">
                        @if ($citySlug)<a href="{{ route('customer.shop.store', [$citySlug, $store->slug]) }}" wire:navigate class="hover:underline">{{ $store->name }}</a>@else{{ $store->name }}@endif
                    </h2>
                    @unless ($store->is_open)<span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-500">Closed now</span>@endunless
                </div>
                <ul class="mt-3 divide-y divide-slate-100">
                    @foreach ($g['lines'] as $line)
                        @php $p = $line->product; $gone = ! $p || ! $p->is_active || ! $p->is_approved; @endphp
                        <li class="flex items-center gap-3 py-3" wire:key="line-{{ $line->id }}">
                            <div class="h-14 w-14 shrink-0 overflow-hidden rounded-xl bg-slate-50">
                                @if ($p && ($img = $p->image_urls[0] ?? null))<img src="{{ $img }}" alt="" class="h-full w-full object-cover">@endif
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold">{{ $p?->name ?? 'Item removed' }}@if ($line->variant) <span class="font-normal text-slate-500">({{ $line->variant->name }})</span>@endif</p>
                                @if ($gone)
                                    <p class="text-xs font-semibold text-red-700">No longer sold — remove it</p>
                                @else
                                    <p class="text-sm text-slate-600">{{ $currencySymbol }}{{ number_format((float) ($line->variant ? $line->variant->effectivePrice() : $p->effective_price), 2) }}</p>
                                @endif
                            </div>
                            @unless ($gone)
                                <div class="flex items-center rounded-lg border border-slate-300">
                                    <button type="button" wire:click="change({{ $line->id }}, -1)" class="min-h-9 px-3 font-bold" aria-label="One less">−</button>
                                    <span class="min-w-6 text-center text-sm font-bold tabular-nums">{{ $line->quantity }}</span>
                                    <button type="button" wire:click="change({{ $line->id }}, 1)" class="min-h-9 px-3 font-bold" aria-label="One more">+</button>
                                </div>
                            @endunless
                            <button type="button" wire:click="removeLine({{ $line->id }})" class="text-xs font-medium text-slate-500 hover:text-red-700" aria-label="Remove">Remove</button>
                        </li>
                    @endforeach
                </ul>
                <div class="mt-3 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-3">
                    <p class="text-sm">Items: <span class="font-bold">{{ $currencySymbol }}{{ number_format($g['subtotal'], 2) }}</span>
                        @if ($g['short'] > 0)<span class="block text-xs text-amber-700">Add {{ $currencySymbol }}{{ number_format($g['short'], 2) }} more to reach this shop's minimum order.</span>@endif
                    </p>
                    @if ($g['canCheckout'] && $g['short'] <= 0)
                        <a href="{{ route('customer.shop.checkout', $store->id) }}" wire:navigate class="inline-flex min-h-11 items-center rounded-xl bg-blue-600 px-5 text-sm font-bold text-white hover:bg-blue-700">Checkout →</a>
                    @else
                        <span class="inline-flex min-h-11 items-center rounded-xl bg-slate-200 px-5 text-sm font-bold text-slate-500">Checkout</span>
                    @endif
                </div>
            </section>
        @empty
            <div class="mt-6 rounded-3xl border border-dashed border-slate-300 bg-white p-8 text-center">
                <p class="font-semibold">Your bag is empty</p>
                <p class="mt-1 text-sm text-slate-600">Find something from a shop near you.</p>
            </div>
        @endforelse
    </div>
</div>
