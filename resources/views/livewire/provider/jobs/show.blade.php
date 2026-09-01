<div @if ($isLive) wire:poll.10s @endif>
    <a href="{{ route('provider.jobs.index') }}" wire:navigate class="text-sm text-slate-500 hover:text-slate-900">← All jobs</a>

    <div class="mt-2 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold tracking-tight">{{ $booking->service?->name ?? 'Service' }}</h1>
            <p class="text-sm text-slate-500">{{ $booking->code }} · {{ str_replace('_', ' ', $booking->status) }}</p>
        </div>
        <p class="text-sm font-semibold">₹{{ number_format((float) ($booking->price_final ?? $booking->price_quoted), 2) }}</p>
    </div>

    @if ($notice)
        <div role="status" class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ $notice }}</div>
    @endif
    @if ($error)
        <div role="alert" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $error }}</div>
    @endif

    @if ($stuckMinutes)
        <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            You've been {{ str_replace('_', ' ', $booking->status) }} on this job for
            {{ $stuckMinutes >= 120 ? floor($stuckMinutes / 60).'h '.($stuckMinutes % 60).'m' : $stuckMinutes.' min' }}.
            {{ $booking->status === 'assigned' ? 'Start it now,' : 'Complete it,' }} or contact your dispatcher if you can't finish it.
        </div>
    @endif

    {{-- ===================== Customer & address ===================== --}}
    <x-ui.card class="mt-4 !p-5">
        <h2 class="text-sm font-semibold text-gray-500 uppercase">Customer</h2>
        <p class="mt-2 text-sm">{{ $booking->customer?->name ?? '—' }}</p>
        @if ($booking->customer?->phone)
            <a href="tel:{{ $booking->customer->phone }}" class="text-sm text-blue-600 hover:underline">{{ $booking->customer->phone }}</a>
        @endif
        <h2 class="mt-4 text-sm font-semibold text-gray-500 uppercase">Address</h2>
        <p class="mt-2 text-sm">{{ $booking->address?->address_line ?? '—' }}</p>
        @if ($booking->address?->landmark)<p class="text-sm text-slate-500">Landmark: {{ $booking->address->landmark }}</p>@endif
        @if ($booking->address && $booking->address->lat && $booking->address->lng)
            <a target="_blank" rel="noopener"
               href="https://maps.google.com/?q={{ $booking->address->lat }},{{ $booking->address->lng }}"
               class="mt-1 inline-flex text-sm text-blue-600 hover:underline">Open in maps</a>
        @endif
    </x-ui.card>

    {{-- ===================== OTP step ===================== --}}
    @if ($booking->status === 'assigned')
        <x-ui.card class="mt-4 !p-5">
            <h2 class="text-sm font-semibold text-gray-500 uppercase">Start the job</h2>
            <p class="mt-1 text-sm text-slate-600">Ask the customer for their <strong>start OTP</strong> and enter it.</p>
            <form wire:submit="start" class="mt-3 flex gap-2">
                <input type="text" inputmode="numeric" autocomplete="one-time-code" wire:model="otp"
                       class="min-h-11 w-36 rounded-lg border border-slate-300 px-3 text-base tracking-widest shadow-sm focus:outline focus:outline-2 focus:outline-blue-600">
                <x-ui.button type="submit" size="lg" wire:loading.attr="disabled" wire:target="start">Start</x-ui.button>
            </form>
            @error('otp') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
        </x-ui.card>
    @elseif ($booking->status === 'in_progress')
        <x-ui.card class="mt-4 !p-5">
            <h2 class="text-sm font-semibold text-gray-500 uppercase">Complete the job</h2>
            <p class="mt-1 text-sm text-slate-600">Ask the customer for their <strong>completion OTP</strong> once the work is done.</p>
            <form wire:submit="complete" class="mt-3 flex gap-2">
                <input type="text" inputmode="numeric" autocomplete="one-time-code" wire:model="otp"
                       class="min-h-11 w-36 rounded-lg border border-slate-300 px-3 text-base tracking-widest shadow-sm focus:outline focus:outline-2 focus:outline-blue-600">
                <x-ui.button type="submit" size="lg" wire:loading.attr="disabled" wire:target="complete">Complete</x-ui.button>
            </form>
            @error('otp') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
        </x-ui.card>
    @elseif ($booking->status === 'completed')
        <x-ui.card class="mt-4 !p-5">
            <p class="text-sm font-semibold text-emerald-700">Job completed.</p>
            @if ($commission)
                <p class="mt-1 text-sm text-slate-600">Your earnings: ₹{{ number_format((float) $commission->provider_commission, 2) }} — added to your wallet.</p>
            @endif
        </x-ui.card>
    @endif

    {{-- ===================== Timeline ===================== --}}
    <x-ui.card class="mt-4 !p-5">
        <h2 class="text-sm font-semibold text-gray-500 uppercase">Timeline</h2>
        <ol class="mt-3 space-y-2 text-sm">
            @forelse ($booking->statusHistory as $h)
                <li class="flex justify-between gap-3">
                    <span>{{ str_replace('_', ' ', $h->status) }}@if ($h->note) — <span class="text-slate-500">{{ $h->note }}</span>@endif</span>
                    <span class="shrink-0 text-xs text-slate-400">{{ \Illuminate\Support\Carbon::parse($h->changed_at)->format('j M, g:i A') }}</span>
                </li>
            @empty
                <li class="text-slate-500">No history yet.</li>
            @endforelse
        </ol>
    </x-ui.card>
</div>
