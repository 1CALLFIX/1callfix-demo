<div class="flex items-center gap-2">
    @if ($error)
        <span role="alert" class="hidden text-xs text-rose-600 sm:inline">{{ $error }}</span>
    @endif

    <span class="flex items-center gap-1.5 text-xs font-medium {{ $provider->is_online ? 'text-emerald-600' : 'text-slate-500' }}">
        <span aria-hidden="true" @class([
            'h-2 w-2 rounded-full',
            'bg-emerald-500' => $provider->is_online,
            'bg-slate-400' => ! $provider->is_online,
        ])></span>
        <span class="hidden sm:inline">{{ $provider->is_online ? 'Online' : 'Offline' }}</span>
    </span>

    @if ($provider->is_online)
        <button type="button" wire:click="goOffline"
                class="inline-flex min-h-9 items-center rounded-lg border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-800 hover:bg-slate-50">
            Go offline
        </button>

        {{-- Location heartbeat: a marker that exists only while online. The
             interval lives in the `providerHeartbeat` Alpine component
             (resources/js/provider-alerts.js), which the page shares across
             this chip, its drawer twin and the Dashboard card, and which
             stops when this element is removed (offline, navigate, morph). --}}
        <div x-data="providerHeartbeat" aria-hidden="true"></div>
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
                class="inline-flex min-h-9 items-center rounded-lg bg-emerald-600 px-3 text-xs font-semibold text-white hover:bg-emerald-700 disabled:opacity-50">
            Go online
        </button>
    @endif
</div>
