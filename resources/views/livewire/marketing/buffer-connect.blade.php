{{-- Connect Buffer (owner 2026-11-22): Super Admin only, two numbered steps. --}}
<div class="mb-6 rounded-2xl bg-white p-4 ring-2 ring-violet-200" data-buffer-connect>
    <div class="mb-3 flex flex-wrap items-center gap-2">
        <h2 class="font-semibold text-violet-900">🟪 Post through Buffer</h2>
        @if ($connected)
            <span class="rounded-full bg-green-100 px-3 py-0.5 text-xs font-semibold text-green-800">Connected{{ $orgName ? ' · '.$orgName : '' }}</span>
        @elseif ($broken)
            <span class="rounded-full bg-red-100 px-3 py-0.5 text-xs font-semibold text-red-800">Needs a new key</span>
        @else
            <span class="rounded-full bg-gray-100 px-3 py-0.5 text-xs font-semibold text-gray-700">Not connected</span>
        @endif
    </div>

    @if ($message)
        <p @class(['mb-3 rounded-lg p-3 text-sm', 'bg-red-50 text-red-800' => $messageType === 'error',
            'bg-green-50 text-green-800' => $messageType === 'success', 'bg-violet-50 text-violet-900' => $messageType === 'info'])>{{ $message }}</p>
    @endif

    {{-- 1 · API key --}}
    <div class="mb-3 rounded-xl bg-violet-50/60 p-3">
        <p class="text-sm font-semibold text-violet-900">1 · Your Buffer API key</p>
        <p class="mt-1 text-xs text-gray-600">In Buffer: your picture (top right) → <b>Settings</b> → <b>API</b> → <b>+ New Key</b>, then copy it here. It is saved locked and never shown again.</p>
        @if ($broken)
            <p class="mt-1 text-sm text-red-700">Buffer stopped accepting the key ({{ $broken }}). Make a new key and paste it.</p>
        @endif
        <div class="mt-2 flex flex-wrap items-start gap-2">
            <div>
                <input type="password" autocomplete="off" wire:model="apiKey" placeholder="{{ $hasKey ? 'API key (saved ✓, paste to change)' : 'Paste the API key' }}" class="min-h-11 w-80 max-w-full rounded-lg border px-2 text-sm">
                @error('apiKey') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <button type="button" wire:click="connect" wire:loading.attr="disabled" class="min-h-11 rounded-xl bg-violet-600 px-5 text-sm font-semibold text-white hover:bg-violet-700" data-buffer-connect-button>{{ $hasKey ? 'Save & connect again' : 'Connect Buffer' }}</button>
            @if ($hasKey)
                <button type="button" wire:click="disconnect" wire:confirm="Stop posting through Buffer?" class="min-h-11 rounded-xl bg-white px-4 text-sm text-gray-600 ring-1 ring-gray-300">Disconnect</button>
            @endif
        </div>
    </div>

    {{-- 2 · Channels --}}
    <div class="rounded-xl bg-fuchsia-50/60 p-3">
        <div class="flex flex-wrap items-center gap-2">
            <p class="text-sm font-semibold text-fuchsia-900">2 · Which Buffer channel each place posts to</p>
            @if ($hasKey)
                <button type="button" wire:click="refreshChannels" wire:loading.attr="disabled" class="min-h-11 rounded-full bg-white px-4 text-xs font-semibold text-fuchsia-800 ring-1 ring-fuchsia-300">🔄 Refresh channels</button>
            @endif
        </div>
        @if (! $hasKey)
            <p class="mt-1 text-xs text-gray-600">Connect Buffer first. Channels are the pages and profiles you added in Buffer (e.g. your Google Business Profile).</p>
        @elseif ($groups === [])
            <p class="mt-1 text-sm text-amber-800">No channel we can post to yet. Add your Google Business Profile (or another place) in Buffer, then press Refresh channels.</p>
        @else
            <div class="mt-2 space-y-2">
                @foreach ($groups as $group)
                    <div class="flex flex-wrap items-center gap-2" data-buffer-group="{{ $group['platform'] }}">
                        <span class="min-w-[9rem] rounded-full px-3 py-1 text-center text-sm font-semibold {{ $group['colour'] }}">{{ $group['label'] }}</span>
                        @foreach ($group['channels'] as $channel)
                            @if ($channel['usable'])
                                <button type="button" wire:click="choose('{{ $group['platform'] }}', '{{ $channel['id'] }}')"
                                        @class(['min-h-11 rounded-full px-4 text-sm font-semibold',
                                            'bg-fuchsia-600 text-white' => $group['chosen'] === $channel['id'],
                                            'bg-white text-gray-700 ring-1 ring-gray-300' => $group['chosen'] !== $channel['id']])>{{ $group['chosen'] === $channel['id'] ? '✓ ' : '' }}{{ $channel['name'] }}</button>
                            @else
                                <span class="rounded-full bg-gray-100 px-3 py-1 text-xs text-gray-500">{{ $channel['name'] }} (reconnect it in Buffer)</span>
                            @endif
                        @endforeach
                    </div>
                @endforeach
            </div>
        @endif
        @if ($others !== [])
            <p class="mt-2 text-xs text-gray-500">Also in Buffer, not used here: {{ collect($others)->map(fn ($c) => $c['name'].' ('.$c['service'].')')->implode(', ') }}</p>
        @endif
    </div>
</div>
