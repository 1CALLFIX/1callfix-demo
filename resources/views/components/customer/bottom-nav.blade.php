@php
    /*
     | Mobile-only sticky navigation. Destinations mirror the desktop
     | header; the ones whose real screens land in later phases route to
     | customer.coming-soon, same as the header (see routes/web.php).
     |
     | `route` + `param` rather than a pre-built href so aria-current can be
     | compared against the resolved URL, and so a Phase C/D swap is a
     | one-line change here.
     */
    $items = [
        ['label' => 'Home', 'route' => 'customer.home', 'param' => null, 'icon' => 'home'],
        ['label' => 'Services', 'route' => 'customer.categories.index', 'param' => null, 'icon' => 'wrench'],
        ['label' => 'Search', 'route' => 'customer.search', 'param' => null, 'icon' => 'magnifying-glass'],
        ['label' => 'Bookings', 'route' => auth()->check() ? 'customer.orders.index' : 'customer.login', 'param' => null, 'icon' => 'clipboard'],
        ['label' => 'Account', 'route' => auth()->check() ? 'customer.account' : 'customer.login', 'param' => null, 'icon' => 'users'],
    ];
@endphp

{{-- lg:hidden, NOT md:hidden. The desktop primary nav in
     components/customer/header.blade.php only appears at `lg` (1024px), so
     hiding this bar at `md` (768px) left the whole 768–1023px tablet range
     with no primary navigation at all — caught by the breakpoint probe in
     browser testing, not by any unit test. The two breakpoints must stay in
     lockstep: whatever width the header nav appears at is the width this bar
     disappears at. --}}
<nav aria-label="Primary mobile"
     class="lg:hidden fixed inset-x-0 bottom-0 z-40 border-t border-slate-200 bg-white pb-safe">
    <ul class="grid grid-cols-5">
        @foreach ($items as $item)
            @php
                $href = $item['param'] ? route($item['route'], $item['param']) : route($item['route']);
                $isCurrent = url()->current() === $href;
            @endphp
            <li>
                <a href="{{ $href }}"
                   @if ($isCurrent) aria-current="page" @endif
                   @class([
                       'flex min-h-16 flex-col items-center justify-center gap-1 px-1 py-2 text-[11px] font-medium transition focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-slate-900',
                       'text-slate-900' => $isCurrent,
                       'text-slate-500' => ! $isCurrent,
                   ])>
                    {{-- The active item is distinguished by weight and an
                         underline bar as well as colour, never colour alone
                         (WCAG 2.1 AA 1.4.1). --}}
                    <x-icon :name="$item['icon']" class="h-5 w-5" />
                    <span>{{ $item['label'] }}</span>
                    <span aria-hidden="true"
                          @class([
                              'block h-0.5 w-6 rounded-full',
                              'bg-slate-900' => $isCurrent,
                              'bg-transparent' => ! $isCurrent,
                          ])></span>
                </a>
            </li>
        @endforeach
    </ul>
</nav>
