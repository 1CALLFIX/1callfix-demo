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

    Admin Polish + AI session -- added the "overflow-x-auto" wrapper around
    <table> itself. Every table on every admin screen (this component is
    used platform-wide, not just the Services vertical) had no horizontal
    scroll container: a wide table (Bookings\Index's 8 columns, etc.) on a
    narrower-than-desktop viewport just overflowed the page and pushed the
    sidebar/layout wide, rather than scrolling within its own card -- a real
    usability bug for franchise staff not always on a full monitor (this
    session's own Part 1 item 5). The outer div keeps "overflow-hidden" so
    the card's own rounded corners still clip correctly; the new inner div
    is the one that actually scrolls.
--}}

<div {{ $attributes->merge(['class' => 'bg-white rounded-lg shadow-sm overflow-hidden']) }}>
    @isset($header)
        <div class="font-semibold p-4 pb-2">{{ $header }}</div>
    @endisset

    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            {{ $slot }}
        </table>
    </div>

    @isset($footer)
        <div class="px-4 py-3 border-t">{{ $footer }}</div>
    @endisset
</div>
