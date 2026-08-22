<div>
    <h1 class="text-2xl font-bold mb-1">Payment Gateways</h1>
    <p class="text-sm text-gray-500 mb-4">
        Currently live: <span class="font-medium">{{ $currentlyResolvedGatewayName }}</span>
        <x-ui.badge :color="$currentlyResolvedGatewayConfigured ? 'green' : 'gray'">
            {{ $currentlyResolvedGatewayConfigured ? 'Configured' : 'Not configured' }}
        </x-ui.badge>
        — this is whichever gateway below is active with the highest priority, or the environment-configured
        Razorpay default when none is active.
    </p>

    @if ($flashMessage)
        <div @class(['rounded px-4 py-2 mb-4 text-sm', 'bg-green-50 text-green-700' => $flashType === 'success', 'bg-red-50 text-red-700' => $flashType === 'error'])>
            {{ $flashMessage }}
        </div>
    @endif

    <x-ui.card class="mb-6">
        <h2 class="text-sm font-semibold text-gray-500 uppercase mb-3">Add a gateway</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div>
                <label class="block text-xs font-medium mb-1">Name</label>
                <input type="text" wire:model="name" placeholder="e.g. Razorpay Primary" class="w-full border rounded px-3 py-2 text-sm">
                @error('name') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs font-medium mb-1">Driver</label>
                <select wire:model.live="driver" class="w-full border rounded px-3 py-2 text-sm">
                    @foreach ($knownDrivers as $slug => $label)
                        <option value="{{ $slug }}">{{ $label }}</option>
                    @endforeach
                </select>
                @if (! in_array($driver, $activatableDrivers, true))
                    <p class="text-xs text-amber-600 mt-1">Not yet available to activate — merchant onboarding pending.</p>
                @endif
            </div>
            <div>
                <label class="block text-xs font-medium mb-1">Mode</label>
                <select wire:model="mode" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="test">Test</option>
                    <option value="live">Live</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium mb-1">Priority</label>
                <input type="number" min="0" wire:model="priority" class="w-full border rounded px-3 py-2 text-sm">
                @error('priority') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-3 gap-3 mt-3">
            @foreach ($credentialFields[$driver] ?? [] as $key => $label)
                <div>
                    <label class="block text-xs font-medium mb-1">{{ $label }}</label>
                    <input type="password" autocomplete="off" wire:model="credentialInputs.{{ $key }}" class="w-full border rounded px-3 py-2 text-sm font-mono">
                    @error("credentialInputs.{$key}") <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            @endforeach
        </div>

        <div class="flex justify-end pt-4 mt-4 border-t">
            <x-ui.button size="lg" wire:click="save">Add Gateway</x-ui.button>
        </div>
    </x-ui.card>

    <x-ui.table>
        <thead class="bg-gray-50 text-left text-gray-500">
            <tr>
                <th class="px-4 py-2">Name</th>
                <th class="px-4 py-2">Driver</th>
                <th class="px-4 py-2">Mode</th>
                <th class="px-4 py-2">Credentials</th>
                <th class="px-4 py-2">Priority</th>
                <th class="px-4 py-2">Status</th>
                <th class="px-4 py-2">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($gateways as $gateway)
                <tr class="border-t hover:bg-gray-50">
                    <td class="px-4 py-2 font-medium">{{ $gateway->name }}</td>
                    <td class="px-4 py-2">{{ $knownDrivers[$gateway->driver] ?? $gateway->driver }}</td>
                    <td class="px-4 py-2 text-gray-500">{{ ucfirst($gateway->mode) }}</td>
                    <td class="px-4 py-2 text-gray-400 text-xs font-mono">{{ $gateway->maskedCredentialSummary() }}</td>
                    <td class="px-4 py-2 text-gray-500">{{ $gateway->priority }}</td>
                    <td class="px-4 py-2">
                        <x-ui.badge :color="$gateway->is_active ? 'green' : 'gray'">{{ $gateway->is_active ? 'Active' : 'Inactive' }}</x-ui.badge>
                        @if (! in_array($gateway->driver, $activatableDrivers, true))
                            <span class="block text-[11px] text-amber-600 mt-0.5">Onboarding pending</span>
                        @endif
                    </td>
                    <td class="px-4 py-2 whitespace-nowrap">
                        <x-ui.button variant="ghost" wire:click="edit({{ $gateway->id }})">Edit</x-ui.button>
                        <x-ui.button variant="ghost" wire:click="toggleActive({{ $gateway->id }})">
                            {{ $gateway->is_active ? 'Deactivate' : 'Activate' }}
                        </x-ui.button>
                        <x-ui.button variant="ghost" color="red" wire:click="confirmDelete({{ $gateway->id }})">Delete</x-ui.button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-6 text-center text-gray-400">No gateways configured yet — Razorpay is running on environment credentials by default.</td></tr>
            @endforelse
        </tbody>
    </x-ui.table>

    {{-- Edit modal --}}
    <x-ui.modal :show="$showEditModal" title="Edit Gateway" onClose="closeEditModal" maxWidth="lg">
        <div class="space-y-4">
            <div>
                <label class="block text-sm font-medium mb-1">Name</label>
                <input type="text" wire:model="editName" class="w-full border rounded px-3 py-2 text-sm">
                @error('editName') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium mb-1">Mode</label>
                    <select wire:model="editMode" class="w-full border rounded px-3 py-2 text-sm">
                        <option value="test">Test</option>
                        <option value="live">Live</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Priority</label>
                    <input type="number" min="0" wire:model="editPriority" class="w-full border rounded px-3 py-2 text-sm">
                    @error('editPriority') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            <p class="text-xs text-gray-500">
                Leave any credential field below blank to keep its current, already-saved value unchanged.
            </p>
            <div class="grid grid-cols-2 gap-3">
                @foreach ($credentialFields[$editDriver] ?? [] as $key => $label)
                    <div>
                        <label class="block text-sm font-medium mb-1">{{ $label }}</label>
                        <input type="password" autocomplete="off" placeholder="Unchanged" wire:model="editCredentialInputs.{{ $key }}" class="w-full border rounded px-3 py-2 text-sm font-mono">
                    </div>
                @endforeach
            </div>
        </div>

        <div class="flex justify-end gap-2 pt-6">
            <x-ui.button variant="secondary" wire:click="closeEditModal">Close</x-ui.button>
            <x-ui.button wire:click="update">Update</x-ui.button>
        </div>
    </x-ui.modal>

    {{-- Delete confirmation --}}
    <x-ui.modal :show="(bool) $confirmingDeleteId" title="Delete this gateway?" onClose="cancelDelete">
        <p class="text-sm text-gray-600 mb-4">This can't be undone. If it's the only active gateway, checkout falls back to the environment-configured Razorpay default.</p>
        <div class="flex justify-end gap-2">
            <x-ui.button variant="secondary" wire:click="cancelDelete">Cancel</x-ui.button>
            <x-ui.button variant="danger" wire:click="deleteGateway">Delete</x-ui.button>
        </div>
    </x-ui.modal>
</div>
