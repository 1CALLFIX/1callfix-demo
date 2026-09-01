<div>
    <h1 class="text-xl font-bold tracking-tight">Hi, {{ $provider->user?->name ?? 'there' }}</h1>
    <p class="text-sm text-slate-500">{{ $provider->zone?->name ? $provider->zone->name.' · ' : '' }}KYC {{ $provider->kyc_status }}</p>

    @if ($notice)
        <div role="status" class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ $notice }}</div>
    @endif
    @if ($error)
        <div role="alert" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $error }}</div>
    @endif

    {{-- ===================== Online / offline ===================== --}}
    <x-ui.card class="mt-4 !p-5">
        <div class="flex items-center justify-between gap-4">
            <div>
                <p class="text-sm font-semibold">
                    You are
                    <span class="{{ $provider->is_online ? 'text-emerald-600' : 'text-slate-500' }}">
                        {{ $provider->is_online ? 'online' : 'offline' }}
                    </span>
                </p>
                <p class="mt-0.5 text-xs text-slate-500">
                    @if ($provider->location_updated_at)
                        Location updated {{ $provider->location_updated_at->diffForHumans() }}
                    @else
                        No location on file
                    @endif
                </p>
            </div>

            @if ($provider->is_online)
                <button type="button" wire:click="goOffline"
                        class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-800 hover:bg-slate-50">
                    Go offline
                </button>
            @else
                <button type="button" x-data
                        x-on:click="
                            $el.disabled = true;
                            navigator.geolocation
                                ? navigator.geolocation.getCurrentPosition(
                                    p => { $wire.goOnline(p.coords.latitude, p.coords.longitude); $el.disabled = false; },
                                    () => { $wire.goOnline(null, null); $el.disabled = false; },
                                    { timeout: 8000 })
                                : ($wire.goOnline(null, null), $el.disabled = false)
                        "
                        class="inline-flex min-h-11 items-center rounded-lg bg-emerald-600 px-4 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50">
                    Go online
                </button>
            @endif
        </div>

        @if ($provider->is_online)
            <div x-data x-init="
                setInterval(() => {
                    if (document.hidden || !navigator.geolocation) return;
                    navigator.geolocation.getCurrentPosition(
                        p => $wire.goOnline(p.coords.latitude, p.coords.longitude),
                        () => {}, { timeout: 8000 });
                }, 120000)
            "></div>
        @endif
    </x-ui.card>

    {{-- ===================== Eligibility panel ===================== --}}
    <x-ui.card class="mt-4 !p-5" title="Will you get jobs?">
        @if ($dispatchBlocked)
            <p class="mb-3 rounded-lg bg-rose-50 px-3 py-2 text-sm font-medium text-rose-800">
                You will not be offered jobs right now — see the unmet items below.
            </p>
        @else
            <p class="mb-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-800">
                You're eligible for dispatch.
            </p>
        @endif

        <ul class="space-y-2 text-sm">
            @foreach ($checks as $c)
                <li class="flex items-start gap-2">
                    <span class="mt-0.5 {{ $c['ok'] ? 'text-emerald-600' : ($c['advisory'] ? 'text-amber-500' : 'text-rose-600') }}">
                        {{ $c['ok'] ? '✓' : ($c['advisory'] ? '!' : '✕') }}
                    </span>
                    <span>
                        <span class="font-medium">{{ $c['label'] }}</span>
                        @unless ($c['ok'])
                            <span class="block text-slate-600">{{ $c['fail'] }}</span>
                        @endunless
                    </span>
                </li>
            @endforeach
        </ul>
    </x-ui.card>

    {{-- ===================== Active / stuck job ===================== --}}
    @if ($activeJob)
        <x-ui.card class="mt-4 !p-5">
            @if ($stuckMinutes)
                <p class="mb-2 rounded-lg bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800">
                    Needs attention — this job has been {{ str_replace('_', ' ', $activeJob->status) }} for
                    {{ $stuckMinutes >= 120 ? floor($stuckMinutes / 60).'h '.($stuckMinutes % 60).'m' : $stuckMinutes.' min' }}.
                    Continue it, or contact your dispatcher.
                </p>
            @endif
            <p class="text-sm font-semibold">Current job: {{ $activeJob->service?->name ?? 'Service' }}</p>
            <p class="text-xs text-slate-500">{{ $activeJob->code }} · {{ str_replace('_', ' ', $activeJob->status) }}</p>
            <a href="{{ route('provider.jobs.show', $activeJob) }}" wire:navigate
               class="mt-3 inline-flex text-sm font-medium text-blue-600 hover:underline">Open job →</a>
        </x-ui.card>
    @endif

    <a href="{{ route('provider.jobs.index') }}" wire:navigate
       class="mt-6 inline-flex text-sm font-medium text-blue-600 hover:underline">View job offers →</a>
</div>
