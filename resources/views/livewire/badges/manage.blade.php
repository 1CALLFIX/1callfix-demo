<div>
    <h1 class="text-2xl font-bold mb-4">Badges</h1>

    @if ($flashMessage)
        <div @class(['rounded px-4 py-2 mb-4 text-sm', 'bg-green-50 text-green-700' => $flashType === 'success', 'bg-red-50 text-red-700' => $flashType === 'error'])>
            {{ $flashMessage }}
        </div>
    @endif

    <div class="flex gap-2 mb-4 border-b">
        <button type="button" wire:click="setSection('definitions')" @class(['px-4 py-2 text-sm font-medium border-b-2', 'border-slate-900 text-slate-900' => $section === 'definitions', 'border-transparent text-gray-400' => $section !== 'definitions'])>Definitions</button>
        <button type="button" wire:click="setSection('assignments')" @class(['px-4 py-2 text-sm font-medium border-b-2', 'border-slate-900 text-slate-900' => $section === 'assignments', 'border-transparent text-gray-400' => $section !== 'assignments'])>Assignments</button>
    </div>

    @if ($section === 'definitions')
        <x-ui.table>
            <thead class="bg-gray-50 text-left text-gray-500">
                <tr>
                    <th class="px-4 py-2">Badge</th>
                    <th class="px-4 py-2">Key</th>
                    <th class="px-4 py-2">Mode</th>
                    <th class="px-4 py-2">Rule</th>
                    <th class="px-4 py-2">Priority</th>
                    <th class="px-4 py-2">Status</th>
                    @if ($canManage)
                        <th class="px-4 py-2">Actions</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach ($badges as $badge)
                    <tr class="border-t hover:bg-gray-50">
                        <td class="px-4 py-2">
                            <span class="px-2 py-0.5 rounded text-xs font-semibold" style="color: {{ $badge->text_color }}; background-color: {{ $badge->bg_color }};">{{ $badge->label }}</span>
                            <div class="text-xs text-gray-400 mt-0.5">{{ $badge->description }}</div>
                        </td>
                        <td class="px-4 py-2 font-mono text-xs text-gray-500">{{ $badge->key }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ ucfirst($badge->mode) }}</td>
                        <td class="px-4 py-2 text-gray-400 text-xs">
                            @if ($badge->rule_type)
                                {{ $badge->rule_type }} ({{ json_encode($badge->rule_config) }})
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-2 text-gray-500">{{ $badge->priority }}</td>
                        <td class="px-4 py-2">
                            <x-ui.badge :color="$badge->is_active ? 'green' : 'gray'">{{ $badge->is_active ? 'Active' : 'Inactive' }}</x-ui.badge>
                        </td>
                        @if ($canManage)
                            <td class="px-4 py-2">
                                <x-ui.button variant="ghost" wire:click="toggleBadgeActive({{ $badge->id }})">
                                    {{ $badge->is_active ? 'Deactivate' : 'Activate' }}
                                </x-ui.button>
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table>
    @endif

    @if ($section === 'assignments')
        @if ($canManage)
            <x-ui.card class="mb-6">
                <h2 class="text-sm font-semibold text-gray-500 uppercase mb-3">Assign a badge to a service</h2>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <div>
                        <label class="block text-xs font-medium mb-1">Badge</label>
                        <select wire:model="assignBadgeId" class="w-full border rounded px-3 py-2 text-sm">
                            <option value="">Select a manual badge…</option>
                            @foreach ($badges->where('mode', 'manual') as $badge)
                                <option value="{{ $badge->id }}">{{ $badge->label }}</option>
                            @endforeach
                        </select>
                        @error('assignBadgeId') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div class="relative">
                        <label class="block text-xs font-medium mb-1">Service</label>
                        <input type="text" wire:model.live.debounce.300ms="serviceSearch" placeholder="Search service…" class="w-full border rounded px-3 py-2 text-sm">
                        @error('selectedServiceId') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                        @if ($this->matchingServices->isNotEmpty())
                            <div class="absolute z-10 bg-white border rounded shadow-sm w-full mt-1">
                                @foreach ($this->matchingServices as $s)
                                    <button type="button" wire:click="selectService({{ $s->id }})" class="block w-full text-left px-3 py-1.5 text-sm hover:bg-gray-50">{{ $s->name }}</button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">Scope</label>
                        <select wire:model.live="scopeType" class="w-full border rounded px-3 py-2 text-sm">
                            <option value="global">Global</option>
                            <option value="country">Country</option>
                            <option value="city">City</option>
                            <option value="franchise">Franchise</option>
                            <option value="zone">Zone</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">Custom expiry (optional)</label>
                        <input type="datetime-local" wire:model="customExpiresAt" class="w-full border rounded px-3 py-2 text-sm">
                        @error('customExpiresAt') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>

                @if ($scopeType === 'country')
                    <div class="mt-3"><select wire:model="scopeCountryId" class="border rounded px-3 py-2 text-sm"><option value="">Select country…</option>@foreach ($countries as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></div>
                @elseif ($scopeType === 'city')
                    <div class="mt-3"><select wire:model="scopeCountryId" class="border rounded px-3 py-2 text-sm mr-2"><option value="">Country…</option>@foreach ($countries as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select>
                    <select wire:model="scopeCityId" class="border rounded px-3 py-2 text-sm"><option value="">City…</option>@foreach ($cities as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></div>
                @elseif ($scopeType === 'franchise')
                    <div class="mt-3"><select wire:model="scopeFranchiseId" class="border rounded px-3 py-2 text-sm"><option value="">Select franchise…</option>@foreach ($franchises as $f)<option value="{{ $f->id }}">{{ $f->name }}</option>@endforeach</select></div>
                @elseif ($scopeType === 'zone')
                    <div class="mt-3"><select wire:model="scopeFranchiseId" class="border rounded px-3 py-2 text-sm mr-2"><option value="">Franchise…</option>@foreach ($franchises as $f)<option value="{{ $f->id }}">{{ $f->name }}</option>@endforeach</select>
                    <select wire:model="scopeZoneId" class="border rounded px-3 py-2 text-sm"><option value="">Zone…</option>@foreach ($zones as $z)<option value="{{ $z->id }}">{{ $z->name }}</option>@endforeach</select></div>
                @endif

                <div class="flex justify-end pt-4 mt-4 border-t">
                    <x-ui.button size="lg" wire:click="assign">Assign Badge</x-ui.button>
                </div>
            </x-ui.card>
        @endif

        <x-ui.table>
            <thead class="bg-gray-50 text-left text-gray-500">
                <tr>
                    <th class="px-4 py-2">Badge</th>
                    <th class="px-4 py-2">Entity</th>
                    <th class="px-4 py-2">Scope</th>
                    <th class="px-4 py-2">Expires</th>
                    <th class="px-4 py-2">Status</th>
                    @if ($canManage)
                        <th class="px-4 py-2">Actions</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse ($assignments as $a)
                    <tr class="border-t hover:bg-gray-50">
                        <td class="px-4 py-2">
                            <span class="px-2 py-0.5 rounded text-xs font-semibold" style="color: {{ $a->badge->text_color }}; background-color: {{ $a->badge->bg_color }};">{{ $a->badge->label }}</span>
                        </td>
                        <td class="px-4 py-2">{{ class_basename($a->badgeable_type) }}: {{ $a->badgeable->name ?? "#{$a->badgeable_id}" }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ ucfirst($a->scope_type) }}@if($a->scope_id) #{{ $a->scope_id }}@endif</td>
                        <td class="px-4 py-2 text-gray-500">{{ $a->expires_at?->format('d M Y, h:i A') ?? 'Never' }}</td>
                        <td class="px-4 py-2">
                            <x-ui.badge :color="$a->is_active ? 'green' : 'gray'">{{ $a->is_active ? 'Active' : 'Revoked' }}</x-ui.badge>
                        </td>
                        @if ($canManage)
                            <td class="px-4 py-2">
                                @if ($a->is_active)
                                    <x-ui.button variant="ghost" color="red" wire:click="revoke({{ $a->id }})" wire:confirm="Revoke this badge assignment?">Revoke</x-ui.button>
                                @endif
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ $canManage ? 6 : 5 }}" class="px-4 py-6 text-center text-gray-400">No badge assignments yet.</td></tr>
                @endforelse
            </tbody>
        </x-ui.table>
        <div class="mt-4">{{ $assignments->links() }}</div>
    @endif
</div>
