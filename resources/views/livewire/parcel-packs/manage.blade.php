<div>
    <div class="mb-4">
        <h1 class="text-2xl font-bold">Parcel Packs</h1>
        <p class="text-sm text-gray-500">Prepaid parcels for businesses, bought online or from the wallet. One credit covers the base fare and the distance up to the pack's km; the rest is paid online. A pack is a benefit, so its parcels are never paid in cash. {{ $activePurchases }} pack(s) in use now.</p>
    </div>

    @if ($flash)
        <div class="mb-4 rounded bg-green-50 px-4 py-2 text-sm text-green-700">{{ $flash }}</div>
    @endif

    <x-ui.card class="mb-6">
        <h2 class="mb-3 text-sm font-semibold">{{ $editingId ? 'Edit pack' : 'New pack' }}</h2>
        <form wire:submit="save" class="grid gap-4 sm:grid-cols-3">
            <label class="block text-sm sm:col-span-2"><span class="mb-1 block text-xs font-medium">Name (shown to customers)</span>
                <input type="text" wire:model="name" maxlength="120" placeholder="e.g. Shop 50" class="w-full rounded border-gray-300 text-sm">
                @error('name') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror</label>
            <label class="block text-sm"><span class="mb-1 block text-xs font-medium">Where it is sold</span>
                <select wire:model="franchiseId" class="w-full rounded border-gray-300 text-sm">
                    <option value="">Every city</option>
                    @foreach ($franchises as $f)<option value="{{ $f->id }}">{{ $f->name }}</option>@endforeach
                </select></label>
            <label class="block text-sm"><span class="mb-1 block text-xs font-medium">Parcels</span>
                <input type="text" inputmode="numeric" wire:model="parcelCount" class="w-full rounded border-gray-300 text-sm">
                @error('parcelCount') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror</label>
            <label class="block text-sm"><span class="mb-1 block text-xs font-medium">Each parcel covers up to (km)</span>
                <input type="text" inputmode="decimal" wire:model="maxKm" class="w-full rounded border-gray-300 text-sm">
                @error('maxKm') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror</label>
            <label class="block text-sm"><span class="mb-1 block text-xs font-medium">Price (₹)</span>
                <input type="text" inputmode="decimal" wire:model="price" class="w-full rounded border-gray-300 text-sm">
                @error('price') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror</label>
            <label class="block text-sm"><span class="mb-1 block text-xs font-medium">Valid for (days)</span>
                <input type="text" inputmode="numeric" wire:model="validityDays" class="w-full rounded border-gray-300 text-sm">
                @error('validityDays') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror</label>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="isActive" class="rounded border-gray-300"> On sale</label>
            <div class="flex items-end gap-2">
                <x-ui.button type="submit">{{ $editingId ? 'Save pack' : 'Create pack' }}</x-ui.button>
                @if ($editingId)<x-ui.button variant="secondary" type="button" wire:click="cancelEdit">Cancel</x-ui.button>@endif
            </div>
        </form>
    </x-ui.card>

    <x-ui.table>
        <thead class="bg-gray-50 text-left text-gray-500">
            <tr><th class="px-4 py-2">Pack</th><th class="px-4 py-2">Where</th><th class="px-4 py-2 text-right">Parcels</th><th class="px-4 py-2 text-right">Covers</th><th class="px-4 py-2 text-right">Price</th><th class="px-4 py-2 text-right">Valid</th><th class="px-4 py-2 text-right">In use</th><th class="px-4 py-2"></th></tr>
        </thead>
        <tbody>
            @forelse ($packs as $pack)
                <tr class="border-t" wire:key="pack-{{ $pack->id }}">
                    <td class="px-4 py-2 font-medium">{{ $pack->name }} @unless ($pack->is_active)<x-ui.badge color="gray">Off sale</x-ui.badge>@endunless</td>
                    <td class="px-4 py-2">{{ $pack->franchise?->name ?? 'Every city' }}</td>
                    <td class="px-4 py-2 text-right tabular-nums">{{ $pack->parcel_count }}</td>
                    <td class="px-4 py-2 text-right tabular-nums">{{ rtrim(rtrim(number_format((float) $pack->max_km, 2), '0'), '.') }} km</td>
                    <td class="px-4 py-2 text-right tabular-nums">₹{{ number_format((float) $pack->price, 2) }}</td>
                    <td class="px-4 py-2 text-right tabular-nums">{{ $pack->validity_days }} days</td>
                    <td class="px-4 py-2 text-right tabular-nums">{{ $pack->sold }}</td>
                    <td class="px-4 py-2 text-right whitespace-nowrap">
                        <x-ui.button variant="ghost" wire:click="edit({{ $pack->id }})">Edit</x-ui.button>
                        <x-ui.button variant="ghost" wire:click="toggle({{ $pack->id }})">{{ $pack->is_active ? 'Take off sale' : 'Put on sale' }}</x-ui.button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-6 text-center text-gray-500">No packs yet. Customers see no packs until you create one.</td></tr>
            @endforelse
        </tbody>
    </x-ui.table>
</div>
