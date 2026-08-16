<div>
    <h1 class="text-2xl font-bold mb-1">Modules</h1>
    <p class="text-sm text-gray-500 mb-4">
        Country &rarr; City &rarr; Zone &rarr; Franchise activation. A module can only ever be switched on here if it is a real,
        implemented operational module — everything else is shown for visibility only.
    </p>

    <x-ui.card class="mb-4">
        <div class="flex items-end gap-4">
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Scope level</label>
                <select wire:model.live="scopeLevel" class="border rounded px-3 py-2 text-sm">
                    <option value="country">Country</option>
                    <option value="city">City</option>
                    <option value="zone">Zone</option>
                    <option value="franchise">Franchise</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">{{ ucfirst($scopeLevel) }}</label>
                <select wire:model.live="scopeId" class="border rounded px-3 py-2 text-sm min-w-[16rem]">
                    <option value="">Select {{ $scopeLevel }}...</option>
                    @foreach ($entities as $id => $label)
                        <option value="{{ $id }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </x-ui.card>

    @if (! $scopeId)
        <p class="text-sm text-gray-400 py-6 text-center">Select a {{ $scopeLevel }} above to view/edit its module activation.</p>
    @else
        <x-ui.table>
            <thead class="bg-gray-50 text-left text-gray-500">
                <tr>
                    <th class="px-4 py-2">Module</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2">Effective state</th>
                    <th class="px-4 py-2">Source</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($moduleRows as $row)
                    <tr class="border-t">
                        <td class="px-4 py-2 font-medium">{{ $row['module']->name }}</td>
                        <td class="px-4 py-2">
                            @if ($row['module']->is_implemented)
                                <x-ui.badge color="blue">Implemented</x-ui.badge>
                            @else
                                <x-ui.badge color="gray">Not yet built</x-ui.badge>
                            @endif
                        </td>
                        <td class="px-4 py-2">
                            @if ($row['is_active'])
                                <x-ui.badge color="green">Active</x-ui.badge>
                            @else
                                <x-ui.badge color="gray">Inactive</x-ui.badge>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-xs text-gray-500">
                            @if ($row['set_here'])
                                Set at this {{ $scopeLevel }}
                            @elseif ($row['inherited_from'])
                                Inherited from {{ $row['inherited_from'] }}
                            @else
                                Default ({{ $row['module']->code === 'service' ? 'legacy on' : 'off — no explicit setting' }})
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right">
                            @if ($row['module']->is_implemented)
                                <x-ui.button variant="ghost" wire:click="toggle('{{ $row['module']->code }}')">
                                    {{ $row['is_active'] ? 'Deactivate' : 'Activate' }} here
                                </x-ui.button>
                            @else
                                <span class="text-xs text-gray-400" title="No operational code exists for this module yet — activating it would have no effect.">Not available</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table>
    @endif
</div>
