@props([
    'type', // 'booking' | 'provider_kyc' | 'document' — a key in App\Support\StatusPresenter::MAPS
    'status',
    'size' => 'sm',
])

{{--
    Admin Polish + AI session. Replaces the `<x-ui.badge :color="match($status) {...}">`
    block hand-written on Dashboard, Bookings\Index/Show, Providers\Show, etc.
    — same five-ish statuses, re-derived per screen, color-only. This adds an
    icon alongside the color + text label so status is never conveyed by
    color alone (accessibility — colorblind users, and it reads correctly on
    a screen reader either way since the label text is unchanged). Mapping
    itself lives in App\Support\StatusPresenter, one place, not here.
--}}

@php($p = \App\Support\StatusPresenter::for($type, $status))

<x-ui.badge :color="$p['color']" :size="$size" {{ $attributes->merge(['class' => 'inline-flex items-center gap-1']) }}>
    <x-icon :name="$p['icon']" class="w-3 h-3 shrink-0" />
    <span>{{ $p['label'] }}</span>
</x-ui.badge>
