<div wire:keydown.ctrl.k.window.prevent="openPalette" wire:keydown.meta.k.window.prevent="openPalette">
    <button type="button" wire:click="openPalette" class="hidden md:flex items-center gap-2 text-sm text-gray-300 border border-slate-600 rounded px-3 py-1.5 hover:bg-slate-700">
        <span>Search…</span>
        <span class="text-xs border border-slate-600 rounded px-1.5 py-0.5 text-gray-400">Ctrl K</span>
    </button>
    <button type="button" wire:click="openPalette" class="md:hidden text-gray-300 p-2" aria-label="Search">
        🔍
    </button>

    <x-ui.modal :show="$open" title="Search" onClose="close" maxWidth="lg">
        <input
            type="text"
            wire:model.live.debounce.300ms="query"
            placeholder="Search bookings, customers, providers, orders…"
            autofocus
            class="w-full border rounded px-3 py-2 text-sm mb-4"
        />

        @if (mb_strlen(trim($query)) < 2)
            <div class="text-xs text-gray-400 text-center py-6">Type at least 2 characters to search.</div>
        @elseif (empty($this->results))
            <div class="text-xs text-gray-400 text-center py-6">No matches.</div>
        @else
            <div class="max-h-96 overflow-y-auto -mx-1">
                @foreach ($this->results as $group => $rows)
                    <div class="px-1 mb-3">
                        <div class="text-xs font-semibold text-gray-400 uppercase mb-1">{{ $group }}</div>
                        @foreach ($rows as $row)
                            <a href="{{ $row['route'] }}" class="block text-sm px-2 py-1.5 rounded hover:bg-gray-50 text-gray-700">
                                {{ $row['label'] }}
                            </a>
                        @endforeach
                    </div>
                @endforeach
            </div>
        @endif
    </x-ui.modal>
</div>
