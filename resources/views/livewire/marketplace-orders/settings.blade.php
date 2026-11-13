<div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <div>
            <h1 class="text-2xl font-bold">{{ $pageTitle }}</h1>
            @if ($pageTitle === 'Food Settings')
                <p class="text-sm text-gray-500">Restaurant rules, kitchen timing and order acceptance for Food delivery. Order timings, rider pay, commission and tax are shared with the shop: set them on <a href="{{ route('admin.marketplace-orders.settings') }}" class="underline">Shop Settings</a>. Switch Food on or off per location on the <a href="{{ route('admin.modules.index') }}" class="underline">Modules</a> screen.</p>
            @elseif ($pageTitle === 'Hotel Settings')
                <p class="text-sm text-gray-500">Hotel sign-ups, booking, payment, cancellation and GST rules for Hotels. Switch Hotels on or off per location on the <a href="{{ route('admin.modules.index') }}" class="underline">Modules</a> screen.</p>
            @elseif ($pageTitle === 'Event Settings')
                <p class="text-sm text-gray-500">Organiser sign-ups, ticket sales, commission, refunds and GST rules for Events. Every organiser and every event is approved by the team before tickets go on sale. Switch Events on or off per location on the <a href="{{ route('admin.modules.index') }}" class="underline">Modules</a> screen.</p>
            @else
                <p class="text-sm text-gray-500">Seller sign-ups, order timings and rider pay for the shop (Commerce). Switch the shop on or off per location on the <a href="{{ route('admin.modules.index') }}" class="underline">Modules</a> screen.</p>
            @endif
        </div>
        @if ($pageTitle === 'Hotel Settings')
            <a href="{{ route('admin.hotel-reservations.index') }}" class="text-sm underline">Hotel Reservations →</a>
        @elseif ($pageTitle === 'Event Settings')
            <a href="{{ route('admin.events.index') }}" class="text-sm underline">Events & organisers →</a>
        @else
            <a href="{{ route('admin.marketplace-orders.index') }}" class="text-sm underline">Marketplace Orders →</a>
        @endif
    </div>

    @if ($flashMessage)
        <div @class(['mb-4 rounded px-4 py-2 text-sm', 'bg-green-50 text-green-700' => $flashType === 'success', 'bg-red-50 text-red-700' => $flashType !== 'success'])>{{ $flashMessage }}</div>
    @endif

    <div class="max-w-4xl space-y-4">
        <x-ui.card>
            <div class="flex flex-wrap gap-3 text-sm">
                <label class="block">
                    <span class="block text-xs font-medium mb-1">Applies to</span>
                    <select wire:model.live="scopeType" class="border rounded px-2 py-1.5">
                        <option value="global">Global</option>
                        <option value="franchise">Franchise</option>
                        <option value="zone">Zone</option>
                    </select>
                </label>
                @if ($scopeType !== 'global')
                    <label class="block">
                        <span class="block text-xs font-medium mb-1">Franchise</span>
                        <select wire:model.live="scopeFranchiseId" class="border rounded px-2 py-1.5">
                            <option value="">—</option>
                            @foreach ($franchises as $f)<option value="{{ $f->id }}">{{ $f->name }}</option>@endforeach
                        </select>
                    </label>
                @endif
                @if ($scopeType === 'zone')
                    <label class="block">
                        <span class="block text-xs font-medium mb-1">Zone</span>
                        <select wire:model.live="scopeZoneId" class="border rounded px-2 py-1.5">
                            <option value="">—</option>
                            @foreach ($zones as $z)<option value="{{ $z->id }}">{{ $z->name }}</option>@endforeach
                        </select>
                    </label>
                @endif
            </div>
            <p class="mt-2 text-xs text-gray-500">A value set at a franchise / zone overrides the global one there; blank inherits.</p>
        </x-ui.card>

        <form wire:submit="save" class="space-y-4">
            @foreach ($groups as $group => $items)
                <x-ui.card>
                    <h2 class="mb-3 text-sm font-semibold">{{ $group }}</h2>
                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach ($items as $meta)
                            <label class="block text-sm">
                                <span class="block text-xs font-medium mb-1">
                                    {{ $meta['label'] }} @if ($meta['unit'] ?? null)<span class="text-gray-400">({{ $meta['unit'] }})</span>@endif
                                </span>
                                @if ($meta['type'] === 'switch')
                                    <select wire:model.live="inputs.{{ $meta['field'] }}" class="w-full border rounded px-2 py-2">
                                        <option value="">{{ $scopeType === 'global' ? (($meta['default'] ?? '0') === '1' ? 'On (default)' : 'Off (default)') : 'Inherit' }}</option>
                                        <option value="1">On</option>
                                        <option value="0">Off</option>
                                    </select>
                                @elseif ($meta['type'] === 'text')
                                    <textarea rows="4" wire:model.live.debounce.600ms="inputs.{{ $meta['field'] }}" placeholder="Not configured"
                                              class="w-full border rounded px-2 py-2"></textarea>
                                @elseif ($meta['type'] === 'choice')
                                    <select wire:model.live="inputs.{{ $meta['field'] }}" class="w-full border rounded px-2 py-2">
                                        <option value="">{{ $scopeType === 'global' ? 'Default: '.$meta['options'][$meta['default']] : 'Inherit' }}</option>
                                        @foreach ($meta['options'] as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                                    </select>
                                @else
                                    <input type="text" inputmode="decimal" wire:model.live.debounce.400ms="inputs.{{ $meta['field'] }}"
                                           placeholder="{{ $meta['default'] === null ? 'Not configured' : 'Default '.$meta['default'] }}"
                                           class="w-full border rounded px-2 py-2">
                                @endif
                                <span class="block font-mono text-[11px] text-gray-400">{{ $meta['key'] }}</span>
                                <span class="block text-[11px] text-gray-500">{{ $meta['help'] }}</span>
                                @error('inputs.'.$meta['field']) <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                            </label>
                        @endforeach
                    </div>
                </x-ui.card>
            @endforeach

            <div class="sticky bottom-0 bg-white/90 py-3"><x-ui.button type="submit">Save {{ strtolower($pageTitle) }}</x-ui.button></div>
        </form>
    </div>
</div>
