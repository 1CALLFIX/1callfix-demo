<div>
    <h1 class="text-2xl font-bold mb-1">Clear Data</h1>
    <p class="text-sm text-gray-500 mb-4">
        Export existing data, then clear selected tables to test a fresh state during an app upgrade.
        Every clear is backed up and verified first, and every attempt is permanently logged.
    </p>

    <div class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800 mb-6">
        This tool cannot run in production. It only affects the tables you explicitly select below, never
        anything else — accounts, permissions, and settings can never be cleared by this tool.
    </div>

    @if ($flashMessage)
        <div @class(['rounded p-3 mb-4 text-sm', 'bg-green-50 text-green-700' => $flashType === 'success', 'bg-red-50 text-red-700' => $flashType === 'error'])>
            {{ $flashMessage }}
        </div>
    @endif

    <x-ui.card class="mb-6">
        <h2 class="text-sm font-semibold mb-3">1. Select what to clear</h2>
        <div class="space-y-3">
            @foreach ($modules as $key => $module)
                <label class="flex items-start gap-3 rounded border border-gray-200 p-3 cursor-pointer hover:bg-gray-50">
                    <input type="checkbox" wire:model.live="selectedModules" value="{{ $key }}" class="mt-1">
                    <span>
                        <span class="block font-medium">{{ $module['label'] }}</span>
                        <span class="block text-xs text-gray-500 mt-0.5">{{ $module['description'] }}</span>
                        <span class="block text-xs text-gray-400 mt-1">Tables: {{ implode(', ', $module['tables']) }}</span>
                    </span>
                </label>
            @endforeach
        </div>
    </x-ui.card>

    @if (! empty($selectedModules))
        <x-ui.card class="mb-6">
            <h2 class="text-sm font-semibold mb-3">2. Review what will be cleared</h2>
            <table class="w-full text-sm mb-2">
                <thead>
                    <tr class="text-left text-gray-500">
                        <th class="pb-1">Table</th>
                        <th class="pb-1 text-right">Rows affected</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->affectedRowCounts as $table => $count)
                        <tr class="border-t border-gray-100">
                            <td class="py-1 font-mono text-xs">{{ $table }}</td>
                            <td class="py-1 text-right">{{ number_format($count) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="text-sm font-semibold text-right">Total: {{ number_format($this->totalAffectedRows) }} row(s)</div>
        </x-ui.card>

        <x-ui.card class="mb-6 border-red-200">
            <h2 class="text-sm font-semibold mb-3 text-red-700">3. Confirm</h2>
            <p class="text-xs text-gray-500 mb-3">
                A fresh backup of exactly these tables is taken and verified automatically before anything is
                cleared — if that verification fails for any reason, nothing is touched.
            </p>
            <p class="text-sm mb-2">
                Type the phrase below exactly to proceed. It is generated for this selection only and cannot
                be reused for a different run.
            </p>
            <div class="font-mono text-base font-bold bg-gray-900 text-white rounded px-3 py-2 mb-3 select-all inline-block">
                {{ $confirmationPhrase }}
            </div>
            <input type="text" wire:model="typedConfirmation" placeholder="Type the phrase exactly..."
                   class="w-full border rounded px-3 py-2 text-sm font-mono mb-3" autocomplete="off">
            <x-ui.button type="button" variant="danger" wire:click="execute" wire:loading.attr="disabled" wire:target="execute">
                Clear {{ number_format($this->totalAffectedRows) }} row(s)
            </x-ui.button>
        </x-ui.card>
    @endif
</div>
