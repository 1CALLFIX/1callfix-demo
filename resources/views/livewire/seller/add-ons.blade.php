<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Add-ons</h1>
            <p class="text-sm text-slate-500">Choices a customer makes on a dish: a base, a size of rice, extra toppings.</p>
        </div>
        @unless ($creating)
            <x-ui.button wire:click="startCreate">Add a choice group</x-ui.button>
        @endunless
    </div>

    @if ($flashMessage)
        <div role="status" @class(['rounded-lg px-4 py-2.5 text-sm', 'bg-emerald-50 text-emerald-800' => $flashType === 'success', 'bg-red-50 text-red-800' => $flashType !== 'success'])>{{ $flashMessage }}</div>
    @endif

    @if ($creating)
        <x-ui.card>
            <h2 class="text-lg font-semibold">{{ $editingId ? 'Edit choice group' : 'New choice group' }}</h2>
            <form wire:submit="save" class="mt-4 space-y-4">
                <label class="block text-sm">
                    <span class="font-medium">Name customers see</span>
                    <input type="text" wire:model="name" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-3 text-base" placeholder="For example: Extra toppings">
                    @error('name') <span class="text-sm text-red-700">{{ $message }}</span> @enderror
                </label>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block text-sm">
                        <span class="font-medium">Fewest a customer must pick</span>
                        <input type="number" min="0" max="20" wire:model="minSelect" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-3 text-base">
                        <span class="text-xs text-slate-500">0 = optional.</span>
                        @error('minSelect') <span class="block text-sm text-red-700">{{ $message }}</span> @enderror
                    </label>
                    <label class="block text-sm">
                        <span class="font-medium">Most a customer can pick</span>
                        <input type="number" min="1" max="20" wire:model="maxSelect" class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-3 text-base">
                        @error('maxSelect') <span class="block text-sm text-red-700">{{ $message }}</span> @enderror
                    </label>
                </div>

                <div>
                    <p class="text-sm font-medium">Options</p>
                    <div class="mt-2 space-y-2">
                        @foreach ($options as $i => $option)
                            <div class="flex items-start gap-2" wire:key="opt-{{ $i }}">
                                <div class="flex-1">
                                    <input type="text" wire:model="options.{{ $i }}.name" aria-label="Option name" placeholder="Option name" class="block min-h-11 w-full rounded-lg border border-slate-300 px-3 text-base">
                                    @error("options.$i.name") <span class="text-sm text-red-700">{{ $message }}</span> @enderror
                                </div>
                                <div class="w-28">
                                    <input type="text" inputmode="decimal" wire:model="options.{{ $i }}.price" aria-label="Price" class="block min-h-11 w-full rounded-lg border border-slate-300 px-3 text-base" placeholder="{{ $currencySymbol }}0">
                                    @error("options.$i.price") <span class="text-sm text-red-700">{{ $message }}</span> @enderror
                                </div>
                                <button type="button" wire:click="removeOption({{ $i }})" class="min-h-11 px-2 text-slate-500 hover:text-red-700" aria-label="Remove option">&times;</button>
                            </div>
                        @endforeach
                    </div>
                    <button type="button" wire:click="addOption" class="mt-2 text-sm font-medium text-blue-700">+ Add option</button>
                    <p class="mt-1 text-xs text-slate-500">Price is what the option adds to the dish ({{ $currencySymbol }}0 = free).</p>
                    @error('options') <span class="block text-sm text-red-700">{{ $message }}</span> @enderror
                </div>

                <div class="flex gap-2">
                    <x-ui.button type="submit" wire:loading.attr="disabled">Save</x-ui.button>
                    <x-ui.button type="button" variant="secondary" wire:click="cancel">Cancel</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    <div class="space-y-2">
        @forelse ($groups as $g)
            <x-ui.card class="!p-3" wire:key="group-{{ $g->id }}">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="font-semibold">{{ $g->name }} <span class="text-xs font-normal text-slate-500">{{ $g->ruleText() }}</span></p>
                        <p class="text-sm text-slate-600">{{ $g->addOns->map(fn ($a) => $a->name.((float) $a->price > 0 ? ' +'.$currencySymbol.rtrim(rtrim(number_format((float) $a->price, 2), '0'), '.') : ''))->implode(', ') }}</p>
                        <p class="text-xs text-slate-500">On {{ $g->products_count }} {{ \Illuminate\Support\Str::plural('dish', $g->products_count) }} @unless ($g->is_active)· <span class="font-semibold">Hidden</span>@endunless</p>
                    </div>
                    <div class="flex gap-3 text-xs">
                        <button type="button" wire:click="edit({{ $g->id }})" class="font-medium text-blue-700">Edit</button>
                        <button type="button" wire:click="toggleActive({{ $g->id }})" class="font-medium text-slate-600">{{ $g->is_active ? 'Hide' : 'Show' }}</button>
                        <button type="button" wire:click="delete({{ $g->id }})" wire:confirm="Remove {{ $g->name }}?" class="font-medium text-red-700">Remove</button>
                    </div>
                </div>
            </x-ui.card>
        @empty
            <x-ui.card><p class="text-sm text-slate-600">No choice groups yet.</p></x-ui.card>
        @endforelse
    </div>
</div>
