<div>
    <div class="mb-4">
        <h1 class="text-2xl font-bold">Shop Commission</h1>
        <p class="text-sm text-gray-500">The platform fee on shop goods. The most specific rule wins: shop + category › shop › category in a franchise › category in a city › category › franchise › city. No rule: the seller's negotiated deal, else the franchise fee, else the global default. Delivery fees are not affected.</p>
        @unless ($hq)
            <p class="mt-1 text-sm text-gray-600">You can set rules for your franchise between {{ $bounds[0] ?? '—' }}% and {{ $bounds[1] ?? '—' }}% (Super Admin sets this range).</p>
        @endunless
    </div>

    @if ($flashMessage)
        <div @class(['mb-4 rounded px-4 py-2 text-sm', 'bg-green-50 text-green-700' => $flashType === 'success', 'bg-red-50 text-red-700' => $flashType !== 'success'])>{{ $flashMessage }}</div>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        <x-ui.card class="lg:col-span-2">
            <h2 class="mb-3 text-sm font-semibold">Add or change a rule</h2>
            <form wire:submit="save" class="grid gap-3 sm:grid-cols-2">
                <label class="text-sm">Shop <span class="text-gray-400">(any)</span>
                    <select wire:model="storeId" class="mt-1 block w-full rounded border px-2 py-1.5"><option value="">Any shop</option>@foreach ($stores as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select>
                    @error('storeId') <span class="text-xs text-red-600">{{ $message }}</span> @enderror</label>
                <label class="text-sm">Category <span class="text-gray-400">(any)</span>
                    <select wire:model="categoryId" class="mt-1 block w-full rounded border px-2 py-1.5"><option value="">Any category</option>@foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->parent_id ? '— ' : '' }}{{ $c->name }}</option>@endforeach</select></label>
                <label class="text-sm">Franchise @if ($hq)<span class="text-gray-400">(any)</span>@endif
                    <select wire:model="franchiseId" class="mt-1 block w-full rounded border px-2 py-1.5">@if ($hq)<option value="">Any franchise</option>@endif @foreach ($franchises as $f)<option value="{{ $f->id }}">{{ $f->name }}</option>@endforeach</select>
                    @error('franchiseId') <span class="text-xs text-red-600">{{ $message }}</span> @enderror</label>
                @if ($hq)
                    <label class="text-sm">City <span class="text-gray-400">(any)</span>
                        <select wire:model="cityId" class="mt-1 block w-full rounded border px-2 py-1.5"><option value="">Any city</option>@foreach ($cities as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></label>
                @endif
                <label class="text-sm">Platform fee %
                    <input type="text" inputmode="decimal" wire:model="percent" class="mt-1 block w-full rounded border px-2 py-1.5">
                    @error('percent') <span class="text-xs text-red-600">{{ $message }}</span> @enderror</label>
                <div class="flex items-end"><x-ui.button type="submit">Save rule</x-ui.button></div>
            </form>
        </x-ui.card>

        <x-ui.card>
            <h2 class="mb-2 text-sm font-semibold">Which fee applies?</h2>
            <select wire:model.live="previewStoreId" class="mb-2 block w-full rounded border px-2 py-1.5 text-sm"><option value="">Pick a shop…</option>@foreach ($stores as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select>
            <select wire:model.live="previewCategoryId" class="block w-full rounded border px-2 py-1.5 text-sm"><option value="">No category</option>@foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select>
            @if ($previewRate !== null)<p class="mt-3 text-2xl font-bold" data-testid="commission-preview">{{ rtrim(rtrim(number_format($previewRate, 2), '0'), '.') }}%</p>@endif
        </x-ui.card>
    </div>

    <x-ui.table class="mt-6">
        <thead class="bg-gray-50 text-left text-gray-500">
            <tr><th class="px-4 py-2">Shop</th><th class="px-4 py-2">Category</th><th class="px-4 py-2">Franchise</th><th class="px-4 py-2">City</th><th class="px-4 py-2 text-right">Fee</th><th class="px-4 py-2"></th></tr>
        </thead>
        <tbody>
            @forelse ($rules as $r)
                <tr class="border-t">
                    <td class="px-4 py-2">{{ $r->store?->name ?? 'Any' }}</td>
                    <td class="px-4 py-2">{{ $r->category?->name ?? 'Any' }}</td>
                    <td class="px-4 py-2">{{ $r->franchise?->name ?? 'Any' }}</td>
                    <td class="px-4 py-2">{{ $r->city?->name ?? 'Any' }}</td>
                    <td class="px-4 py-2 text-right font-semibold">{{ rtrim(rtrim(number_format((float) $r->platform_fee_percent, 2), '0'), '.') }}%</td>
                    <td class="px-4 py-2 text-right"><x-ui.button variant="ghost" wire:click="delete({{ $r->id }})" wire:confirm="Remove this rule?">Remove</x-ui.button></td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500">No rules yet — the existing fee hierarchy applies.</td></tr>
            @endforelse
        </tbody>
    </x-ui.table>
</div>
