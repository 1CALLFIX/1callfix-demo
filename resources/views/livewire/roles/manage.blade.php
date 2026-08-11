<div>
    <h1 class="text-2xl font-bold mb-1">Roles & Permissions</h1>
    <p class="text-sm text-gray-500 mb-4">Grant a system role to a user at a specific scope. Super Admin always has full access regardless of assignments below.</p>

    @if ($flashMessage)
        <div @class(['rounded p-3 mb-4 text-sm', 'bg-green-50 text-green-700' => $flashType === 'success', 'bg-red-50 text-red-700' => $flashType === 'error'])>
            {{ $flashMessage }}
        </div>
    @endif

    {{-- Assign a role --}}
    <div class="bg-white rounded-lg shadow-sm p-4 mb-6">
        <h2 class="text-sm font-semibold mb-3">Assign a Role</h2>
        <div class="flex flex-wrap items-end gap-3">
            <div class="w-64 relative">
                <label class="block text-xs font-medium mb-1">User <span class="text-red-500">*</span></label>
                <input type="text" wire:model.live="userSearch" placeholder="Search name or phone..." class="w-full border rounded px-3 py-2 text-sm" autocomplete="off">
                @error('selectedUserId') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                @if ($userSearch && $this->matchingUsers->isNotEmpty() && ! $selectedUserId)
                    <div class="absolute z-10 bg-white border rounded shadow-sm mt-1 w-full max-h-48 overflow-y-auto">
                        @foreach ($this->matchingUsers as $u)
                            <button type="button" wire:click="selectUser({{ $u->id }})" class="block w-full text-left px-3 py-2 text-sm hover:bg-gray-50">
                                {{ $u->name }} <span class="text-gray-400">— {{ $u->phone }} ({{ $u->role }})</span>
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="w-56">
                <label class="block text-xs font-medium mb-1">Role <span class="text-red-500">*</span></label>
                <select wire:model="roleId" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="">Select a role...</option>
                    @foreach ($roles as $r)
                        <option value="{{ $r->id }}">{{ $r->name }}</option>
                    @endforeach
                </select>
                @error('roleId') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="w-40">
                <label class="block text-xs font-medium mb-1">Scope</label>
                <select wire:model.live="scopeType" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="global">Global</option>
                    <option value="country">Country</option>
                    <option value="city">City</option>
                    <option value="franchise">Franchise</option>
                    <option value="zone">Zone</option>
                    <option value="module">Module</option>
                </select>
            </div>

            @if ($scopeType === 'country')
                <div class="w-52">
                    <label class="block text-xs font-medium mb-1">Country</label>
                    <select wire:model="scopeCountryId" class="w-full border rounded px-3 py-2 text-sm">
                        <option value="">Select...</option>
                        @foreach ($countries as $c) <option value="{{ $c->id }}">{{ $c->name }}</option> @endforeach
                    </select>
                </div>
            @elseif ($scopeType === 'city')
                <div class="w-52">
                    <label class="block text-xs font-medium mb-1">Country, then City</label>
                    <select wire:model.live="scopeCountryId" class="w-full border rounded px-3 py-2 text-sm mb-1">
                        <option value="">Select country...</option>
                        @foreach ($countries as $c) <option value="{{ $c->id }}">{{ $c->name }}</option> @endforeach
                    </select>
                    <select wire:model="scopeCityId" class="w-full border rounded px-3 py-2 text-sm">
                        <option value="">Select city...</option>
                        @foreach ($cities as $c) <option value="{{ $c->id }}">{{ $c->name }}</option> @endforeach
                    </select>
                </div>
            @elseif ($scopeType === 'franchise')
                <div class="w-52">
                    <label class="block text-xs font-medium mb-1">Franchise</label>
                    <select wire:model="scopeFranchiseId" class="w-full border rounded px-3 py-2 text-sm">
                        <option value="">Select...</option>
                        @foreach ($franchises as $f) <option value="{{ $f->id }}">{{ $f->name }}</option> @endforeach
                    </select>
                </div>
            @elseif ($scopeType === 'zone')
                <div class="w-52">
                    <label class="block text-xs font-medium mb-1">Franchise, then Zone</label>
                    <select wire:model.live="scopeFranchiseId" class="w-full border rounded px-3 py-2 text-sm mb-1">
                        <option value="">Select franchise...</option>
                        @foreach ($franchises as $f) <option value="{{ $f->id }}">{{ $f->name }}</option> @endforeach
                    </select>
                    <select wire:model="scopeZoneId" class="w-full border rounded px-3 py-2 text-sm">
                        <option value="">Select zone...</option>
                        @foreach ($zones as $z) <option value="{{ $z->id }}">{{ $z->name }}</option> @endforeach
                    </select>
                </div>
            @elseif ($scopeType === 'module')
                <div class="w-52">
                    <label class="block text-xs font-medium mb-1">Module</label>
                    <select wire:model="scopeModuleId" class="w-full border rounded px-3 py-2 text-sm">
                        <option value="">Select...</option>
                        @foreach ($modules as $m) <option value="{{ $m->id }}">{{ $m->name }}</option> @endforeach
                    </select>
                </div>
            @endif

            <button type="button" wire:click="assign" class="bg-slate-900 text-white px-6 h-[38px] rounded text-sm font-medium hover:bg-slate-800">+ Assign</button>
        </div>
    </div>

    {{-- Current assignments --}}
    <div class="bg-white rounded-lg shadow-sm overflow-hidden mb-6">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-gray-500">
                <tr>
                    <th class="px-4 py-2">User</th>
                    <th class="px-4 py-2">Role</th>
                    <th class="px-4 py-2">Scope</th>
                    <th class="px-4 py-2 text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($assignments as $a)
                    <tr class="border-t hover:bg-gray-50" wire:key="assignment-{{ $a->id }}">
                        <td class="px-4 py-2">{{ $a->user->name ?? '#'.$a->user_id }} <span class="text-gray-400">({{ $a->user->phone ?? '—' }})</span></td>
                        <td class="px-4 py-2 font-medium">{{ $a->role->name ?? '—' }}</td>
                        <td class="px-4 py-2 text-gray-500">
                            @if ($a->scope_type === 'global') Global
                            @else {{ ucfirst($a->scope_type) }} #{{ $a->scope_id }}
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right">
                            @if ($confirmingRevokeId === $a->id)
                                <span class="text-xs text-red-600">Revoke?</span>
                                <button type="button" wire:click="revoke" class="text-xs text-red-600 font-medium hover:underline ml-2">Yes</button>
                                <button type="button" wire:click="cancelRevoke" class="text-xs text-gray-500 hover:underline ml-2">No</button>
                            @else
                                <button type="button" wire:click="confirmRevoke({{ $a->id }})" class="text-xs text-red-600 hover:underline">Revoke</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-6 text-center text-gray-400">No role assignments yet — Super Admin has full access by default.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-4 py-3 border-t">{{ $assignments->links() }}</div>
    </div>

    {{-- Role -> permission catalog (read-only reference) --}}
    <div class="bg-white rounded-lg shadow-sm p-4">
        <h2 class="text-sm font-semibold mb-3">Role Catalog</h2>
        <div class="space-y-3">
            @foreach ($roles as $r)
                <div class="border rounded p-3">
                    <div class="font-medium text-sm">{{ $r->name }}</div>
                    <div class="text-xs text-gray-500 mb-2">{{ $r->description }}</div>
                    <div class="flex flex-wrap gap-1">
                        @forelse ($r->permissions as $p)
                            <span class="px-2 py-0.5 bg-gray-100 text-gray-700 rounded text-xs">{{ $p->label }}</span>
                        @empty
                            <span class="text-xs text-gray-400">No permissions granted.</span>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
