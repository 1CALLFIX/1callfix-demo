@props(['items', 'label' => 'Sections'])
{{-- Partner apps 2026-11-04 (owner: "easy to use, like the delivery apps"): one app-style bottom bar for the rider,
     seller/restaurant and service-partner apps, on every screen size. Each item: label, route, icon, optional `also`
     (more route names that light the same tab) and optional `dot` (a red count badge). --}}
<nav aria-label="{{ $label }}" class="fixed inset-x-0 bottom-0 z-40 border-t border-slate-200 bg-white/95 shadow-[0_-8px_24px_-12px_rgba(15,23,42,0.15)] backdrop-blur pb-safe" data-testid="partner-bottom-nav">
    <ul class="mx-auto grid max-w-3xl" style="grid-template-columns: repeat({{ count($items) }}, minmax(0, 1fr));">
        @foreach ($items as $item)
            @php $isCurrent = request()->routeIs($item['route'], ...($item['also'] ?? [])); @endphp
            <li>
                <a href="{{ route($item['route']) }}" wire:navigate @if ($isCurrent) aria-current="page" @endif
                   @class([
                       'relative flex min-h-16 flex-col items-center justify-center gap-1 px-1 py-2 text-xs font-semibold transition',
                       'text-blue-600' => $isCurrent,
                       'text-slate-500 hover:text-slate-800' => ! $isCurrent,
                   ])>
                    <span class="relative">
                        <x-icon :name="$item['icon']" class="h-6 w-6" />
                        @if (! empty($item['dot']))
                            <span class="absolute -right-2.5 -top-1.5 min-w-[1.1rem] rounded-full bg-rose-600 px-1 text-center text-[10px] font-bold leading-[1.1rem] text-white">{{ $item['dot'] }}</span>
                        @endif
                    </span>
                    <span class="truncate">{{ $item['label'] }}</span>
                    <span aria-hidden="true" @class(['block h-0.5 w-6 rounded-full', 'bg-blue-600' => $isCurrent, 'bg-transparent' => ! $isCurrent])></span>
                </a>
            </li>
        @endforeach
    </ul>
</nav>
