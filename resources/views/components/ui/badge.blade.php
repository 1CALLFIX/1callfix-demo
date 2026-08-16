@props([
    'color' => 'gray',
    'size' => 'sm',
])

{{--
    Phase 21 item TECH-6 (Admin UI design system, first increment). The
    status-pill pattern hand-written with @class(...) on nearly every list
    screen (Customers\Index status column, Wallet Ledger's credit/debit and
    successful/pending/failed columns, Payments\Index, Subscriptions\Index,
    Badges\Manage active/inactive, ...) — same five-color palette every
    time, just re-derived per screen. This component only standardizes the
    palette + shape; callers still decide which color a given status maps
    to (that mapping is domain logic, not something a shared component
    should own) and can still pass extra classes/attributes as needed.

    Color -> meaning, matching what's already in use everywhere else:
      green  - active / successful / credit / approved
      red    - suspended / failed / debit / rejected
      amber  - pending / grace period / warning
      blue   - refunded / informational
      slate  - cancelled / neutral-dark
      gray   - default / unknown / inactive

    $size defaults to the small table-cell pill everywhere uses ("sm"); a
    couple of screens (e.g. Customers\Show's page-header status) use a
    larger variant of the identical palette instead of a smaller inline
    style override, since Tailwind utility class precedence isn't reliably
    controllable by class order alone.
--}}

@php
    $colors = [
        'green' => 'bg-green-100 text-green-700',
        'red' => 'bg-red-100 text-red-700',
        'amber' => 'bg-amber-100 text-amber-700',
        'blue' => 'bg-blue-100 text-blue-700',
        'slate' => 'bg-slate-100 text-slate-700',
        'gray' => 'bg-gray-100 text-gray-700',
    ];

    $sizes = [
        'sm' => 'px-2 py-0.5 text-xs',
        'lg' => 'px-3 py-1.5 text-sm',
    ];

    $colorClass = $colors[$color] ?? $colors['gray'];
    $sizeClass = $sizes[$size] ?? $sizes['sm'];
@endphp

<span {{ $attributes->merge(['class' => "inline-block rounded font-medium {$sizeClass} {$colorClass}"]) }}>{{ $slot }}</span>
