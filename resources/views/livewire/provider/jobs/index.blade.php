<div wire:poll.4s>
    <h1 class="text-xl font-bold tracking-tight">Job offers</h1>

    @if ($notice)
        <div role="status" class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ $notice }}</div>
    @endif
    @if ($error)
        <div role="alert" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $error }}</div>
    @endif

    @if ($activeJob)
        <a href="{{ route('provider.jobs.show', $activeJob) }}" wire:navigate
           class="mt-4 block rounded-xl border border-blue-200 bg-blue-50/70 p-4 hover:bg-blue-50">
            <p class="text-sm font-semibold text-slate-900">You're on a job — {{ $activeJob->service?->name ?? 'Service' }}</p>
            <p class="text-xs text-slate-600">{{ $activeJob->code }} · {{ str_replace('_', ' ', $activeJob->status) }} · tap to open</p>
        </a>
    @endif

    <div class="mt-4 space-y-3">
        @forelse ($offers as $offer)
            @php $b = $offer->booking; @endphp
            <x-ui.card class="!p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold">{{ $b->service?->name ?? 'Service' }}</p>
                        <p class="mt-0.5 text-xs text-slate-500">
                            {{ $b->code }}
                            @if ($offer->distance_km !== null) · {{ number_format((float) $offer->distance_km, 1) }} km @endif
                            @if ($b->scheduled_at) · {{ \Illuminate\Support\Carbon::parse($b->scheduled_at)->format('j M, g:i A') }} @else · ASAP @endif
                        </p>
                        <p class="mt-1 text-xs text-slate-500">{{ $b->address?->label }} — area only until accepted</p>
                        <p class="mt-1 text-sm font-medium">₹{{ number_format((float) $b->price_quoted, 2) }}</p>
                    </div>
                    <div class="flex shrink-0 gap-2">
                        <button type="button" wire:click="decline({{ $offer->booking_id }})"
                                class="min-h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-medium text-slate-700 hover:bg-slate-50">
                            Decline
                        </button>
                        <button type="button" wire:click="accept({{ $offer->booking_id }})" wire:loading.attr="disabled"
                                class="min-h-10 rounded-lg bg-emerald-600 px-4 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50">
                            Accept
                        </button>
                    </div>
                </div>
            </x-ui.card>
        @empty
            <x-ui.card class="!p-6 text-center text-sm text-slate-500">
                No live offers right now. This page refreshes on its own — keep it open and in the foreground.
            </x-ui.card>
        @endforelse
    </div>
</div>
