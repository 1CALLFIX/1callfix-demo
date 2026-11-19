{{-- Account → Rent out your things (Rent anything, owner decision 2026-11-18): your posted items, one main action.
     Account → Second-hand ($sale, 2026-11-19): your used things for sale, with Mark as sold. --}}
<div class="mb-bottom-nav">
    <div class="mx-auto max-w-3xl px-4 py-8 sm:px-6">
        <h1 class="text-2xl font-bold tracking-tight text-slate-900">{{ $sale ? 'Your second-hand posts' : 'Rent out your things' }}</h1>
        <p class="mt-1 text-sm text-slate-600">{{ $sale ? 'Turn things you no longer use into money. We check each post, then people near you call or WhatsApp you. No commission.' : 'Earn from things you do not use every day. We check each post, then people near you call or WhatsApp you. No commission.' }}</p>

        @if ($notice) <div role="status" class="mt-4 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800" data-testid="items-notice">{{ $notice }}</div> @endif
        @error('items') <div role="alert" class="mt-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700" data-testid="items-error">{{ $message }}</div> @enderror

        @if (! $open && $items->isEmpty())
            <div role="status" class="mt-6 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700" data-testid="items-not-open">Posting is not open in your area yet. Please check again soon.</div>
        @elseif ($blocked)
            <div role="status" class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" data-testid="items-blocked">{{ $blocked }}</div>
        @else
            <a href="{{ route($createRoute) }}" wire:navigate class="mt-6 flex min-h-14 items-center justify-center gap-2 rounded-2xl bg-blue-600 px-5 text-base font-semibold text-white shadow-sm hover:bg-blue-700" data-testid="items-new">
                ➕ {{ $sale ? 'Post a used thing' : 'Rent out something' }}
            </a>
        @endif
        <p class="mt-2 text-xs text-slate-500">{{ $up }} of {{ $max }} {{ $sale ? 'used things' : 'items' }} up or waiting for review.</p>

        @if ($items->isNotEmpty())
            <ul class="mt-6 space-y-3">
                @foreach ($items as $item)
                    <li class="cf-card flex gap-3 p-3" wire:key="ri-{{ $item->id }}" data-testid="items-row">
                        <a href="{{ route($editRoute, $item->id) }}" wire:navigate class="h-20 w-20 shrink-0 overflow-hidden rounded-xl bg-slate-100">
                            @if ($item->coverUrl()) <img src="{{ $item->coverUrl() }}" alt="" class="h-full w-full object-cover"> @else <span class="grid h-full place-items-center text-2xl">📦</span> @endif
                        </a>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <a href="{{ route($editRoute, $item->id) }}" wire:navigate class="truncate font-semibold text-slate-900 hover:underline">{{ $item->name }}</a>
                                @php
                                    [$label, $colour] = match (true) {
                                        $item->isSold() => ['Sold', 'bg-violet-100 text-violet-800'],
                                        $item->status === 'approved' && $item->hidden_for_reports_at !== null => ['Being checked', 'bg-amber-100 text-amber-800'],
                                        $item->status === 'approved' && $item->isExpired() => ['Ran out', 'bg-slate-200 text-slate-700'],
                                        $item->status === 'approved' && ! $item->is_active => ['Hidden by you', 'bg-slate-200 text-slate-700'],
                                        $item->status === 'approved' => ['Up', 'bg-emerald-100 text-emerald-800'],
                                        $item->status === 'in_review' => ['Waiting for review', 'bg-amber-100 text-amber-800'],
                                        $item->status === 'rejected' => ['Needs changes', 'bg-red-100 text-red-700'],
                                        default => ['Draft', 'bg-slate-100 text-slate-700'],
                                    };
                                @endphp
                                <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $colour }}" data-testid="items-badge">{{ $label }}</span>
                            </div>
                            <p class="mt-0.5 text-sm text-slate-600">₹{{ number_format((float) $item->price) }}{{ $sale ? ($item->negotiable ? ' · can be discussed' : '') : ' per '.mb_strtolower((string) $item->price_unit) }}@if ($item->expires_at && $item->status === 'approved' && ! $item->isSold()) · {{ $item->isExpired() ? 'ran out' : 'up until' }} {{ $item->expires_at->format('j M') }}@endif</p>
                            @if ($item->status === 'rejected' && $item->rejection_reason)
                                <p class="mt-1 text-sm text-red-700">{{ $item->rejection_reason }}</p>
                            @endif
                            @if ($item->status === 'approved' && ! $item->isSold())
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @if ($sale)
                                        <button type="button" wire:click="markSold({{ $item->id }})" wire:confirm="Mark &quot;{{ $item->name }}&quot; as sold? It stops showing to customers." class="min-h-11 rounded-xl bg-violet-600 px-4 text-sm font-semibold text-white" data-testid="items-sold">✅ Mark as sold</button>
                                    @endif
                                    @if ($item->isExpired())
                                        <button type="button" wire:click="renew({{ $item->id }})" class="min-h-11 rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white" data-testid="items-renew">🔄 Renew</button>
                                    @elseif ($sale)
                                    @elseif ($item->is_active)
                                        <button type="button" wire:click="notAvailable({{ $item->id }})" class="min-h-11 rounded-xl border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-800" data-testid="items-off">⏸️ Not available now</button>
                                    @else
                                        <button type="button" wire:click="availableAgain({{ $item->id }})" class="min-h-11 rounded-xl bg-emerald-600 px-4 text-sm font-semibold text-white" data-testid="items-on">Available again</button>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
