<div class="space-y-4">
    <div>
        <a href="{{ route('seller.products') }}" wire:navigate class="text-sm font-medium text-blue-700">← Products</a>
        <h1 class="mt-1 text-2xl font-bold tracking-tight">Units &amp; choices</h1>
        <p class="text-sm text-slate-600">{{ $product->name }} · {{ $currencySymbol }}{{ number_format((float) $product->price, 2) }}@if ($product->discount_percent) · {{ rtrim(rtrim(number_format((float) $product->discount_percent, 1), '0'), '.') }}% off (online only, on every choice)@endif</p>
    </div>

    @if ($flashMessage)
        <div role="status" @class(['rounded-lg px-4 py-2.5 text-sm', 'bg-emerald-50 text-emerald-800' => $flashType === 'success', 'bg-red-50 text-red-800' => $flashType !== 'success'])>{{ $flashMessage }}</div>
    @endif

    <form wire:submit="save" class="space-y-4">
        <x-ui.card>
            <h2 class="font-semibold">What is one item?</h2>
            <p class="text-sm text-slate-600">For example 500 g, 1 kg, 1 L, 6 piece. Customers see it under the price. Leave blank if it does not matter.</p>
            <div class="mt-3 flex flex-wrap items-end gap-2">
                <label class="block text-sm">
                    <span class="font-medium">Amount</span>
                    <input type="text" inputmode="decimal" wire:model="unitValue" class="mt-1 block min-h-11 w-28 rounded-lg border border-slate-300 px-3 text-base" placeholder="500">
                </label>
                <label class="block text-sm">
                    <span class="font-medium">Unit</span>
                    <select wire:model="unit" class="mt-1 block min-h-11 w-32 rounded-lg border border-slate-300 px-3 text-base">
                        <option value="">—</option>
                        @foreach ($units as $u)<option value="{{ $u }}">{{ $u }}</option>@endforeach
                    </select>
                </label>
            </div>
            @error('unitValue') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
            @error('unit') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
        </x-ui.card>

        <x-ui.card>
            <h2 class="font-semibold">Choices the customer picks</h2>
            <p class="text-sm text-slate-600">Does it come in sizes, colours, weights or portions? Add up to {{ $maxTypes }}. Type the values with commas, then press “Make the list”.</p>

            <datalist id="choice-names">@foreach ($choiceNames as $n)<option value="{{ $n }}">@endforeach</datalist>
            <div class="mt-3 space-y-3">
                @foreach ($types as $i => $type)
                    <div class="grid gap-2 rounded-lg border border-slate-200 p-3 sm:grid-cols-[10rem_1fr_auto]" wire:key="type-{{ $i }}">
                        <label class="block text-sm">
                            <span class="font-medium">Choice</span>
                            <input type="text" list="choice-names" wire:model="types.{{ $i }}.name" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-3 text-base" placeholder="Size">
                            @error("types.$i.name") <span class="text-sm text-red-700">{{ $message }}</span> @enderror
                        </label>
                        <label class="block text-sm">
                            <span class="font-medium">Values</span>
                            <input type="text" wire:model="types.{{ $i }}.values" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-3 text-base" placeholder="S, M, L, XL">
                            @error("types.$i.values") <span class="text-sm text-red-700">{{ $message }}</span> @enderror
                        </label>
                        <button type="button" wire:click="removeType({{ $i }})" class="self-end pb-2 text-sm font-medium text-red-700">Remove</button>
                    </div>
                @endforeach
            </div>
            <div class="mt-3 flex flex-wrap gap-2">
                @if (count($types) < $maxTypes)
                    <x-ui.button type="button" variant="secondary" wire:click="addType">+ Add a choice</x-ui.button>
                @endif
                @if ($types)
                    <x-ui.button type="button" variant="secondary" wire:click="buildRows">Make the list</x-ui.button>
                @endif
            </div>
            @error('types') <p class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror

            @if ($rows)
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-xs text-slate-500">
                            <tr>
                                <th class="py-1 pr-2 font-medium">Choice</th>
                                <th class="py-1 pr-2 font-medium">Price ({{ $currencySymbol }})</th>
                                <th class="py-1 pr-2 font-medium">Stock</th>
                                @if ($photos)<th class="py-1 pr-2 font-medium">Photo</th>@endif
                                <th class="py-1 font-medium">On sale</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($rows as $r => $row)
                                <tr wire:key="row-{{ md5(json_encode($row['options'])) }}">
                                    <td class="py-1.5 pr-2 font-medium">{{ $row['label'] }}</td>
                                    <td class="py-1.5 pr-2">
                                        <input type="text" inputmode="decimal" wire:model="rows.{{ $r }}.price" placeholder="{{ number_format((float) $product->price, 2, '.', '') }}" aria-label="Price for {{ $row['label'] }}"
                                               class="w-24 rounded border border-slate-300 px-2 py-1 text-right">
                                        @error("rows.$r.price") <span class="block text-xs text-red-700">{{ $message }}</span> @enderror
                                    </td>
                                    <td class="py-1.5 pr-2">
                                        <input type="text" inputmode="numeric" wire:model="rows.{{ $r }}.stock" aria-label="Stock for {{ $row['label'] }}"
                                               class="w-20 rounded border border-slate-300 px-2 py-1 text-right">
                                        @error("rows.$r.stock") <span class="block text-xs text-red-700">{{ $message }}</span> @enderror
                                    </td>
                                    @if ($photos)
                                        <td class="py-1.5 pr-2">
                                            <select wire:model="rows.{{ $r }}.image" aria-label="Photo for {{ $row['label'] }}" class="rounded border border-slate-300 px-2 py-1">
                                                <option value="">Main photo</option>
                                                @foreach ($photos as $p => $path)<option value="{{ $path }}">Photo {{ $p + 1 }}</option>@endforeach
                                            </select>
                                        </td>
                                    @endif
                                    <td class="py-1.5"><input type="checkbox" wire:model="rows.{{ $r }}.active" class="h-5 w-5 rounded border-slate-300" aria-label="{{ $row['label'] }} on sale"></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <p class="mt-2 text-xs text-slate-500">Blank price = the product price. The product's stock becomes the total of these.</p>
                </div>
            @endif
        </x-ui.card>

        <div class="flex flex-wrap gap-2">
            <x-ui.button type="submit" wire:loading.attr="disabled">Save</x-ui.button>
            @if ($product->option_names)
                <x-ui.button type="button" variant="secondary" wire:click="removeChoices" wire:confirm="Take all choices off {{ $product->name }}? It will sell as one item.">Remove all choices</x-ui.button>
            @endif
        </div>
    </form>
</div>
