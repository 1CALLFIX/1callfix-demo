<div class="space-y-5">
    <x-partner.join-banner class="mb-4" />
    <div>
        <h1 class="text-2xl font-bold tracking-tight">{{ $hotel->name }}</h1>
        <p class="mt-1 text-sm text-slate-600">{{ $hotel->accommodationType?->name }} · {{ $hotel->address_line }}</p>
    </div>

    @if ($hotel->approval_status === 'pending')
        <div role="status" class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" data-testid="hotel-pending">
            <p class="font-semibold">Waiting for approval</p>
            <p class="mt-1">Our team checks your documents and photos. Finish the list below so we can approve you faster.</p>
        </div>
    @elseif ($hotel->approval_status === 'rejected')
        <div role="alert" class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-800" data-testid="hotel-rejected">
            <p class="font-semibold">Not approved yet</p>
            @if ($hotel->rejection_reason) <p class="mt-1">{{ $hotel->rejection_reason }}</p> @endif
            <p class="mt-1">Fix this and call us; we will check again.</p>
        </div>
    @elseif (! $hotel->is_active)
        <div class="rounded-2xl border border-slate-200 bg-white p-4 text-sm text-slate-700">Your hotel is approved but switched off. Customers cannot see it.</div>
    @elseif (! $moduleLive)
        <div class="rounded-2xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900" data-testid="hotel-approved-soon">
            <p class="font-semibold">Approved</p>
            <p class="mt-1">Hotel booking opens in your city soon. Keep your rooms, prices and calendar ready.</p>
        </div>
    @else
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900" data-testid="hotel-live">
            <p class="font-semibold">Live</p>
            <p class="mt-1">Customers can find and book your hotel. Keep your calendar up to date so nobody books a full night.</p>
        </div>
    @endif

    <div class="grid grid-cols-2 gap-3">
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <p class="text-xs font-semibold uppercase text-slate-500">Rooms on sale</p>
            <p class="mt-1 text-2xl font-bold">{{ $rooms }}</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <p class="text-xs font-semibold uppercase text-slate-500">Lowest price</p>
            <p class="mt-1 text-2xl font-bold">{{ $fromPrice ? '₹'.number_format((float) $fromPrice) : '—' }}</p>
        </div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-4">
        <h2 class="text-sm font-semibold uppercase text-slate-500">{{ $ready ? 'All set' : 'Before you go live' }}</h2>
        <ul class="mt-3 space-y-2">
            @foreach ($checklist as $item)
                <li>
                    <a href="{{ route($item['route']) }}" wire:navigate class="flex min-h-11 items-center gap-3 rounded-xl px-2 hover:bg-slate-50">
                        <span @class(['flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-bold',
                                      'bg-emerald-600 text-white' => $item['done'], 'border-2 border-slate-300 text-transparent' => ! $item['done']])>✓</span>
                        <span @class(['text-sm', 'text-slate-500 line-through' => $item['done'], 'font-medium text-slate-900' => ! $item['done']])>{{ $item['label'] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </div>

    {{-- ── reviews from verified stays (Hotels H4) ── --}}
    <div class="rounded-2xl border border-slate-200 bg-white p-4" data-testid="hotel-reviews">
        <h2 class="text-sm font-semibold uppercase text-slate-500">Reviews {{ $reviewSummary['n'] ? '· ★ '.$reviewSummary['avg'].' from '.$reviewSummary['n'] : '' }}</h2>
        @if ($notice) <p role="status" class="mt-2 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{{ $notice }}</p> @endif
        @forelse ($reviews as $rv)
            <div class="mt-3 border-t border-slate-100 pt-3 text-sm">
                <p><span class="font-semibold">{{ $rv->customer?->name ?? 'Guest' }}</span> <span class="text-amber-500">{{ str_repeat('★', (int) $rv->rating) }}</span></p>
                @if ($rv->comment) <p class="mt-1 text-slate-700">{{ $rv->comment }}</p> @endif
                @if ($rv->provider_reply)
                    <p class="mt-2 rounded-lg bg-slate-50 px-3 py-2 text-slate-700"><span class="font-semibold">Your reply:</span> {{ $rv->provider_reply }}</p>
                @else
                    <form wire:submit="reply({{ $rv->id }})" class="mt-2 flex gap-2">
                        <input type="text" wire:model="replies.{{ $rv->id }}" maxlength="1000" placeholder="Thank the guest or answer the problem" class="min-h-11 flex-1 rounded-lg border border-slate-300 px-3 text-base">
                        <button type="submit" class="min-h-11 rounded-lg bg-blue-600 px-3 text-sm font-semibold text-white">Reply</button>
                    </form>
                    @error('replies.'.$rv->id) <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                @endif
            </div>
        @empty
            <p class="mt-2 text-sm text-slate-600">No reviews yet. Guests can review after they check out.</p>
        @endforelse
    </div>
</div>
