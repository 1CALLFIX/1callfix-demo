@props([
    'user' => null,
    'onDuty' => null,      // partners only: their duty switch (providers.is_online / field_workers.is_online)
    'locationAt' => null,  // partners only: when their phone last sent its location
])

{{--
    The Online column on admin people lists.
    Customers / users: Online = active on web, app or API in the last User::ONLINE_WINDOW_MINUTES minutes.
    Partners (owner 2026-11-09: "he went offline in the app but here it still shows online"): the first line is what
    dispatch uses, so it changes the moment they switch: "Taking jobs" (switch on and the phone sent its location
    within provider.location_stale_after_minutes, the same rule offers use), "On duty · no location" (switch on but
    the phone has gone quiet: no offers reach them), or "Off duty". The second line is when the app was last used.
--}}

@php($online = $user?->isOnline() ?? false)

<div class="leading-tight whitespace-nowrap">
    @if ($onDuty !== null)
        @php($fresh = $locationAt !== null && \Illuminate\Support\Carbon::parse($locationAt)->gt(now()->subMinutes(\App\Services\Providers\ShiftSchedule::staleMinutes())))
        @if ($onDuty && $fresh)
            <span class="inline-flex items-center gap-1.5 text-sm font-medium text-green-700" data-presence="taking-jobs">
                <span class="relative flex h-2.5 w-2.5"><span class="absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75 motion-safe:animate-ping"></span><span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-green-500"></span></span>
                Taking jobs
            </span>
        @elseif ($onDuty)
            <span class="inline-flex items-center gap-1.5 text-sm font-medium text-amber-700" data-presence="no-location" title="The switch is on, but the phone has not sent its location recently, so no offers reach them.">
                <span class="inline-flex h-2.5 w-2.5 rounded-full bg-amber-400"></span>
                On duty · no location
            </span>
        @else
            <span class="inline-flex items-center gap-1.5 text-sm text-gray-500" data-presence="off-duty">
                <span class="inline-flex h-2.5 w-2.5 rounded-full bg-gray-300"></span>
                Off duty
            </span>
        @endif
        <span class="block pl-4 text-xs text-gray-400" @if ($user?->last_seen_at) title="{{ app(\App\Services\TimezoneResolver::class)->format($user->last_seen_at, null, 'd M Y, h:i A') }}" @endif>
            {{ $online ? 'App open now' : ($user?->last_seen_at ? 'Seen '.$user->last_seen_at->diffForHumans(short: true) : 'Not seen yet') }}
        </span>
    @elseif ($online)
        <span class="inline-flex items-center gap-1.5 text-sm font-medium text-green-700">
            <span class="relative flex h-2.5 w-2.5"><span class="absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75 motion-safe:animate-ping"></span><span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-green-500"></span></span>
            Online
        </span>
    @else
        <span class="inline-flex items-center gap-1.5 text-sm text-gray-500">
            <span class="inline-flex h-2.5 w-2.5 rounded-full bg-gray-300"></span>
            Offline
        </span>
        <span class="block pl-4 text-xs text-gray-400" @if ($user?->last_seen_at) title="{{ app(\App\Services\TimezoneResolver::class)->format($user->last_seen_at, null, 'd M Y, h:i A') }}" @endif>
            {{ $user?->last_seen_at ? 'Seen '.$user->last_seen_at->diffForHumans(short: true) : 'Not seen yet' }}
        </span>
    @endif
</div>
