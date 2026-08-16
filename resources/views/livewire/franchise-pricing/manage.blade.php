<div>
    <a href="{{ route('admin.franchises.index') }}" class="text-sm text-blue-600 hover:underline">&larr; Back to Franchises</a>

    <div class="flex items-center justify-between mt-2 mb-4">
        <div>
            <h1 class="text-2xl font-bold">Service Pricing</h1>
            <p class="text-sm text-gray-500">{{ $franchise->display_name }}</p>
        </div>
        <input type="text" wire:model.live.debounce.400ms="search" placeholder="Search services..." class="border rounded px-3 py-2 text-sm w-64">
    </div>

    @if ($flashMessage)
        <div @class(['rounded p-3 mb-4 text-sm', 'bg-green-50 text-green-700' => $flashType === 'success', 'bg-red-50 text-red-700' => $flashType === 'error'])>
            {{ $flashMessage }}
        </div>
    @endif

    <x-ui.table>
        <thead class="bg-gray-50 text-left text-gray-500">
            <tr>
                <th class="px-4 py-2">Service</th>
                <th class="px-4 py-2">Category</th>
                <th class="px-4 py-2">Base price</th>
                <th class="px-4 py-2">Offered here</th>
                <th class="px-4 py-2">Price override</th>
                <th class="px-4 py-2"></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($services as $service)
                <tr class="border-t hover:bg-gray-50" wire:key="row-{{ $service->id }}">
                    <td class="px-4 py-2 font-medium">{{ $service->name }}</td>
                    <td class="px-4 py-2 text-gray-500">{{ $service->category->name ?? '—' }}</td>
                    <td class="px-4 py-2 font-mono text-gray-500">{{ $currencySymbol }}{{ number_format($service->base_price, 2) }}</td>
                    <td class="px-4 py-2">
                        <input type="checkbox" wire:model="rows.{{ $service->id }}.is_offered" class="rounded">
                    </td>
                    <td class="px-4 py-2">
                        <div class="flex items-center gap-1">
                            <span class="text-gray-400">{{ $currencySymbol }}</span>
                            <input type="text" wire:model="rows.{{ $service->id }}.price_override"
                                   placeholder="base price" class="border rounded px-2 py-1 text-sm w-28">
                        </div>
                        @error("rows.{$service->id}.price_override") <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </td>
                    <td class="px-4 py-2">
                        <div class="flex items-center gap-2 justify-end">
                            <x-ui.button size="sm" wire:click="saveRow({{ $service->id }})">Save</x-ui.button>
                            <x-ui.button variant="ghost" color="gray" wire:click="resetRow({{ $service->id }})">Reset</x-ui.button>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400">No active services match your search.</td></tr>
            @endforelse
        </tbody>
    </x-ui.table>
</div>
