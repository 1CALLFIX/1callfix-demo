@props([])

{{--
    Phase 21 item TECH-6 (Admin UI design system, first increment). The
    "bg-white rounded-lg shadow-sm overflow-hidden" + "table w-full text-sm"
    wrapper repeated at the top of every list screen (Providers\Index,
    Customers\Index, Commissions\Index, Payments\Index, and ~25 more). Only
    wraps the outer shell — callers still write their own <thead>/<tbody>
    with the same "bg-gray-50 text-left text-gray-500" header row and
    "border-t hover:bg-gray-50" row classes as before, since those vary
    per-screen (column count/labels) and aren't worth abstracting away from
    plain HTML. Pass pagination links (or any other bottom bar) via the
    named $footer slot, matching the existing "px-4 py-3 border-t" wrapper
    several screens already hand-write around {{ $x->links() }}. An
    optional named $header slot renders above the <table> itself, inside
    the same card, for screens that title the table directly (e.g.
    Customers\Show's "Recent Bookings (N)" — previously its own hand-written
    "font-semibold p-4 pb-2" line inside the same wrapper div).
--}}

<div {{ $attributes->merge(['class' => 'bg-white rounded-lg shadow-sm overflow-hidden']) }}>
    @isset($header)
        <div class="font-semibold p-4 pb-2">{{ $header }}</div>
    @endisset

    <table class="w-full text-sm">
        {{ $slot }}
    </table>

    @isset($footer)
        <div class="px-4 py-3 border-t">{{ $footer }}</div>
    @endisset
</div>
