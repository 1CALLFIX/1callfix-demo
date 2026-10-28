@props([
    'user' => null,
    'onDuty' => null,   // partners only: their duty toggle (providers.is_online / field_workers.is_online)
])

{{--
    The Online column on admin people lists. Online = active on web, app or API in the last
    User::ONLINE_WINDOW_MINUTES minutes (App\Http\Middleware\TouchLastSeen); otherwise "Seen 3h ago".
    For partners a second pill shows whether they are on duty (taking jobs).
--}}

@php($online = $user?->isOnline() ?? false)

<div class="leading-tight whitespace-nowrap">
    @if ($online)
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
    @if ($onDuty !== null)
        <span @class(['mt-1 inline-block rounded px-1.5 py-0.5 text-[11px] font-medium',
                      'bg-blue-50 text-blue-700' => $onDuty, 'bg-gray-100 text-gray-500' => ! $onDuty])>{{ $onDuty ? 'On duty' : 'Off duty' }}</span>
    @endif
</div>
