<div wire:poll.10s>
    {{-- Partner apps 2026-11-04: one big online switch, same look as the rider and seller apps. --}}
    <div @class(['rounded-2xl p-4 text-white', 'bg-emerald-600' => $provider->is_online, 'bg-slate-800' => ! $provider->is_online])
         data-testid="provider-switch-card">
        <div class="flex items-center justify-between gap-3">
            <div class="min-w-0">
                <p class="text-sm font-medium opacity-90">Hi, {{ $provider->user?->name ?? 'there' }}</p>
                <h1 class="text-xl font-bold tracking-tight">{{ $provider->is_online ? 'You are online' : 'You are offline' }}</h1>
                <p class="text-xs opacity-90">{{ $provider->is_online ? 'Job offers will ring on this phone.' : 'Turn on the switch to get job offers.' }}</p>
            </div>
            @if ($provider->is_online)
                <button type="button" wire:click="goOffline" role="switch" aria-checked="true" aria-label="Go offline"
                        class="relative inline-flex h-10 w-[4.5rem] shrink-0 items-center rounded-full bg-white/30 ring-2 ring-white/70" data-testid="online-switch">
                    <span class="absolute right-1 h-8 w-8 rounded-full bg-white shadow"></span>
                </button>
            @else
                <button type="button" x-data role="switch" aria-checked="false" aria-label="Go online"
                        x-on:click="
                            $el.disabled = true;
                            navigator.geolocation
                                ? navigator.geolocation.getCurrentPosition(
                                    p => { $wire.goOnline(p.coords.latitude, p.coords.longitude); $el.disabled = false; },
                                    () => { $wire.goOnline(null, null); $el.disabled = false; },
                                    { timeout: 8000 })
                                : ($wire.goOnline(null, null), $el.disabled = false)
                        "
                        class="relative inline-flex h-10 w-[4.5rem] shrink-0 items-center rounded-full bg-white/20 ring-2 ring-white/40 disabled:opacity-50" data-testid="online-switch">
                    <span class="absolute left-1 h-8 w-8 rounded-full bg-slate-300 shadow"></span>
                </button>
            @endif
        </div>
        <div class="mt-3 flex items-center justify-between gap-3 border-t border-white/20 pt-3 text-xs">
            <p class="opacity-90">
                {{ $provider->zone?->name ? $provider->zone->name.' · ' : '' }}KYC {{ $provider->kyc_status }} ·
                @if ($provider->location_updated_at)
                    Location updated {{ $provider->location_updated_at->diffForHumans() }}
                @else
                    No location on file
                @endif
            </p>
            @if ($provider->is_online)
                <button type="button" x-data
                        x-on:click="
                            $el.disabled = true;
                            navigator.geolocation
                                ? navigator.geolocation.getCurrentPosition(
                                    p => { $wire.refreshLocation(p.coords.latitude, p.coords.longitude); $el.disabled = false; },
                                    () => { $wire.refreshLocation(null, null); $el.disabled = false; },
                                    { timeout: 8000 })
                                : ($wire.refreshLocation(null, null), $el.disabled = false)
                        "
                        class="inline-flex min-h-9 shrink-0 items-center rounded-lg bg-white/20 px-3 font-semibold hover:bg-white/30 disabled:opacity-50">
                    Refresh location
                </button>
                {{-- Location heartbeat marker — see online-toggle.blade.php. --}}
                <div x-data="providerHeartbeat" aria-hidden="true"></div>
            @endif
        </div>
    </div>

    @if ($notice)
        <div role="status" class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ $notice }}</div>
    @endif
    @if ($error)
        <div role="alert" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $error }}</div>
    @endif

    {{-- The layout's ringing offer banner (provider-alerts.js) is the alert
         with JS; this server-rendered link stays as the no-JS fallback so it
         is not shown twice. --}}
    @if ($pendingOffers > 0)
        <noscript>
            <a href="{{ route('provider.jobs.index') }}" wire:navigate
               class="mt-4 flex items-center justify-between gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900 hover:bg-amber-100">
                <span>{{ $pendingOffers === 1 ? 'You have a new job offer' : $pendingOffers.' new job offers' }} — tap to respond</span>
                <span aria-hidden="true">→</span>
            </a>
        </noscript>
    @endif

    {{-- Enable closed-app / locked-phone job-offer push (Phase 2). Shown
         only where the browser supports web push and permission has not
         already been granted; window.pushNotifications is defined by
         resources/js/push-notifications.js (loaded in the provider layout).
         A no-op if Firebase env isn't built in — the card just never
         appears because isSupported() is false. --}}
    <div x-data="providerPushOptIn"
         x-show="show" style="display: none" class="mt-4">
        <div class="flex items-start justify-between gap-4 rounded-xl border border-blue-200 bg-blue-50 px-4 py-3">
            <div class="min-w-0">
                <p class="text-sm font-semibold text-blue-900">Enable job alerts</p>
                <p class="mt-0.5 text-xs text-blue-800">Get a notification for new job offers even when this app is closed.</p>
                <p x-show="msg" x-text="msg" class="mt-1 text-xs text-rose-700"></p>
            </div>
            <button type="button" x-on:click="enable()" x-bind:disabled="busy"
                    class="inline-flex min-h-11 shrink-0 items-center rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60">
                <span x-show="!busy">Enable</span>
                <span x-show="busy">Enabling…</span>
            </button>
        </div>
    </div>

    {{-- ===================== Today at a glance ===================== --}}
    <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="Today at a glance">
        <a href="{{ route('provider.history') }}" wire:navigate class="rounded-xl border border-slate-200 bg-white px-4 py-3 hover:border-blue-300">
            <p class="text-xs text-slate-500">Jobs done today</p>
            <p class="mt-1 text-2xl font-bold text-slate-900">{{ $summary['done_today'] }}</p>
        </a>
        <a href="{{ route('provider.earnings') }}" wire:navigate class="rounded-xl border border-slate-200 bg-white px-4 py-3 hover:border-blue-300">
            <p class="text-xs text-slate-500">Earned today</p>
            <p class="mt-1 text-2xl font-bold text-slate-900">₹{{ number_format($summary['earned_today'], 2) }}</p>
        </a>
        <a href="{{ route('provider.jobs.index') }}" wire:navigate class="rounded-xl border border-slate-200 bg-white px-4 py-3 hover:border-blue-300">
            <p class="text-xs text-slate-500">Upcoming booked jobs</p>
            <p class="mt-1 text-2xl font-bold text-slate-900">{{ $summary['upcoming'] }}</p>
        </a>
        @if ($summary['shift'])
            @php $tzr = app(\App\Services\TimezoneResolver::class); @endphp
            <a href="{{ route('provider.shifts') }}" wire:navigate class="rounded-xl border border-slate-200 bg-white px-4 py-3 hover:border-blue-300">
                <p class="text-xs text-slate-500">Shift</p>
                @if ($summary['shift']['current'])
                    <p class="mt-1 text-sm font-bold text-slate-900">{{ $summary['shift']['current']['shift']->name }}</p>
                    <p class="text-xs text-slate-600">until {{ $tzr->format($summary['shift']['current']['end'], $summary['franchise'], 'h:i A') }}</p>
                @elseif ($summary['shift']['next'])
                    <p class="mt-1 text-sm font-bold text-slate-900">Next: {{ $summary['shift']['next']['shift']->name }}</p>
                    <p class="text-xs text-slate-600">{{ $tzr->format($summary['shift']['next']['start'], $summary['franchise'], 'D, h:i A') }}</p>
                @else
                    <p class="mt-1 text-sm font-bold text-blue-700">Choose your shifts →</p>
                @endif
            </a>
        @endif
    </div>

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
