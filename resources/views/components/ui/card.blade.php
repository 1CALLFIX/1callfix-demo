@props([
    'title' => null,
])

{{--
    Phase 21 item TECH-6 (Admin UI design system, first increment). The
    "bg-white rounded-lg shadow-sm p-4" block repeated on nearly every admin
    screen for stat tiles and detail panels (Commissions\Index's earnings
    tiles, Customers\Show's Details/Wallet panels, Badges\Manage's "Assign a
    badge" panel, etc.) — same classes, copied by hand each time. $title is
    optional and matches the existing "text-sm font-semibold text-gray-500
    uppercase mb-3" section-header convention already used ad hoc across
    those screens; omit it for a plain stat-tile card with custom content.
--}}

<div {{ $attributes->merge(['class' => 'bg-white rounded-lg shadow-sm p-4']) }}>
    @isset($title)
        <h2 class="text-sm font-semibold text-gray-500 uppercase mb-3">{{ $title }}</h2>
    @endisset

    {{ $slot }}
</div>
