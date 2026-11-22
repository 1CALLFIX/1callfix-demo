{{-- How each place posts (owner 2026-11-22): Super Admin only; one row per place, tap a chip. --}}
<div class="mb-6 rounded-2xl bg-white p-4 ring-2 ring-amber-200" data-posting-routes>
    <h2 class="font-semibold text-amber-900">🚦 How each place posts</h2>
    <p class="mb-3 text-xs text-gray-600"><b>Direct</b> = our own Facebook connection · <b>Buffer</b> = through your Buffer account · <b>By hand</b> = the Share button and Done ✓. Each place uses one way only, so nothing is posted twice.</p>

    @if ($message)
        <p class="mb-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">{{ $message }}</p>
    @endif

    <div class="space-y-2">
        @foreach ($rows as $row)
            <div class="flex flex-wrap items-center gap-2 rounded-xl bg-gray-50 p-2" data-route-row="{{ $row['platform'] }}">
                <span class="min-w-[9rem] rounded-full px-3 py-1 text-center text-sm font-semibold {{ $row['colour'] }}">{{ $row['label'] }}</span>
                <div class="flex flex-wrap gap-1">
                    @foreach ($row['options'] as $option)
                        <button type="button" wire:click="setRoute('{{ $row['platform'] }}', '{{ $option }}')"
                                @class(['min-h-11 rounded-full px-4 text-sm font-semibold',
                                    'bg-amber-600 text-white' => $row['route'] === $option,
                                    'bg-white text-gray-700 ring-1 ring-gray-300 hover:bg-amber-50' => $row['route'] !== $option])
                                data-route="{{ $option }}">{{ $row['route'] === $option ? '✓ ' : '' }}{{ $labels[$option] }}</button>
                    @endforeach
                </div>
                @if ($row['route'] === 'manual')
                    <span class="rounded-full bg-gray-200 px-3 py-0.5 text-xs font-semibold text-gray-700">👆 One tap</span>
                @elseif ($row['missing'])
                    <span class="rounded-full bg-red-100 px-3 py-0.5 text-xs font-semibold text-red-800">⚠️ {{ $row['missing'] }}</span>
                @else
                    <span class="rounded-full bg-green-100 px-3 py-0.5 text-xs font-semibold text-green-800">🤖 Posts by itself</span>
                @endif
            </div>
        @endforeach
    </div>
</div>
