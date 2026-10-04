{{-- REF 1CF-CANCEL-POLICY-001 — Super Admin → Cancellation Policy. Every action re-checks server-side. --}}
<div>
    <div class="mb-4 flex flex-wrap items-baseline justify-between gap-2">
        <h1 class="text-2xl font-bold">Cancellation Policy</h1>
        <p class="text-xs text-gray-500">Super Admin only · every change is audited · blank = not configured, 0 = explicitly zero</p>
    </div>

    @if ($flashMessage)
        <div role="status" @class(['rounded px-4 py-2 mb-4 text-sm', 'bg-green-50 text-green-700' => $flashType === 'success', 'bg-red-50 text-red-700' => $flashType === 'error'])>
            {{ $flashMessage }}
        </div>
    @endif

    <div class="mb-4 flex flex-wrap gap-1 border-b">
        @foreach (['settings' => 'Settings', 'categories' => 'Category overrides', 'operations' => 'Operations', 'audit' => 'Audit log'] as $key => $label)
            <button type="button" wire:click="setTab('{{ $key }}')"
                    @class(['px-4 py-2 text-sm font-medium border-b-2 -mb-px', 'border-slate-900 text-slate-900' => $tab === $key, 'border-transparent text-gray-500 hover:text-gray-700' => $tab !== $key])>
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- ═══════════════════════ Settings ═══════════════════════ --}}
    @if ($tab === 'settings')
        <div class="grid gap-4 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <x-ui.card class="mb-4">
                    <div class="flex flex-wrap items-end gap-3 text-sm">
                        <label class="block">
                            <span class="block text-xs font-medium mb-1">Scope</span>
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
                    <p class="mt-2 text-xs text-gray-500">A value set at a franchise / zone overrides the global one there; blank inherits. A change applies to bookings made <strong>after</strong> it — a booking keeps the policy it was made under.</p>
                </x-ui.card>

                <form wire:submit="save" class="space-y-4">
                    @foreach ($groups as $group => $items)
                        <x-ui.card>
                            <h2 class="mb-3 text-sm font-semibold">{{ $group }}</h2>
                            <div class="grid gap-4 sm:grid-cols-2">
                                @foreach ($items as $meta)
                                    <label class="block text-sm">
                                        <span class="block text-xs font-medium mb-1">{{ $meta['label'] }} @if ($meta['unit'])<span class="text-gray-400">({{ $meta['unit'] }})</span>@endif</span>
                                        @if ($meta['type'] === 'enum')
                                            <select wire:model.live="inputs.{{ $meta['field'] }}" class="w-full border rounded px-2 py-2">
                                                <option value="">Default ({{ $meta['options'][$meta['default']] }})</option>
                                                @foreach ($meta['options'] as $val => $text)<option value="{{ $val }}">{{ $text }}</option>@endforeach
                                            </select>
                                        @else
                                            <input type="text" @if ($meta['type'] !== 'text') inputmode="decimal" @endif wire:model.live.debounce.400ms="inputs.{{ $meta['field'] }}"
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

                    <x-ui.card>
                        <p class="text-sm text-gray-600">The Prime Silver waiver toggle (whether visit-fee waivers cover the en-route and visit charges) is on each plan's form: <a href="{{ route('admin.plans.index') }}" class="underline">Plans</a>. Per-category spares-delay overrides are on the <button type="button" wire:click="setTab('categories')" class="underline">Category overrides</button> tab.</p>
                    </x-ui.card>

                    <div class="sticky bottom-0 bg-white/90 py-3"><x-ui.button type="submit">Save policy</x-ui.button></div>
                </form>
            </div>

            <div>
                <x-ui.card class="lg:sticky lg:top-4">
                    <h2 class="mb-1 text-sm font-semibold">What customers will read</h2>
                    <p class="mb-3 text-xs text-gray-500">Live preview of the booking, checkout and tracking policy text from the values on the left.</p>
                    <ul class="list-disc space-y-2 pl-5 text-sm text-gray-700" data-testid="policy-preview">
                        @foreach ($preview as $line)<li>{{ $line }}</li>@endforeach
                    </ul>
                </x-ui.card>
            </div>
        </div>
    @endif

    {{-- ═══════════════════════ Category overrides ═══════════════════════ --}}
    @if ($tab === 'categories')
        <x-ui.card class="mb-4">
            <h2 class="mb-1 text-sm font-semibold">Spares delay per category</h2>
            <p class="mb-3 text-xs text-gray-500">Global value: <strong>{{ $globalDays }} days</strong>. A category listed here uses its own number instead. Applies to bookings made after the change.</p>
            <form wire:submit="addOverride" class="flex flex-wrap items-end gap-3 text-sm">
                <label class="block">
                    <span class="block text-xs font-medium mb-1">Category</span>
                    <select wire:model="overrideCategoryId" class="border rounded px-2 py-2">
                        <option value="">— pick —</option>
                        @foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                    </select>
                    @error('overrideCategoryId') <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
                <label class="block">
                    <span class="block text-xs font-medium mb-1">Days</span>
                    <input type="text" inputmode="numeric" wire:model="overrideDays" class="w-24 border rounded px-2 py-2">
                    @error('overrideDays') <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
                <x-ui.button type="submit">Add override</x-ui.button>
            </form>
        </x-ui.card>

        <x-ui.table>
            <thead class="bg-gray-50 text-left text-gray-500"><tr><th class="px-4 py-2">Category</th><th class="px-4 py-2">Days</th><th class="px-4 py-2"></th></tr></thead>
            <tbody>
                @forelse ($overrides as $o)
                    <tr class="border-t">
                        <td class="px-4 py-2">{{ $o['name'] }}</td>
                        <td class="px-4 py-2">{{ $o['days'] }}</td>
                        <td class="px-4 py-2 text-right"><button type="button" wire:click="removeOverride({{ $o['id'] }})" wire:confirm="Remove this override?" class="text-xs text-red-600 underline">Remove</button></td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-6 text-center text-gray-400">No category overrides — every category uses the global value.</td></tr>
                @endforelse
            </tbody>
        </x-ui.table>
    @endif

    {{-- ═══════════════════════ Operations ═══════════════════════ --}}
    @if ($tab === 'operations')
        <h2 class="mb-2 text-sm font-semibold">Held for spares ({{ $held->count() }})</h2>
        <x-ui.table class="mb-6">
            <thead class="bg-gray-50 text-left text-gray-500"><tr><th class="px-4 py-2">Booking</th><th class="px-4 py-2">Customer</th><th class="px-4 py-2">Professional</th><th class="px-4 py-2">Days counted</th><th class="px-4 py-2">Customer may cancel</th></tr></thead>
            <tbody>
                @forelse ($held as $h)
                    <tr class="border-t">
                        <td class="px-4 py-2 font-mono text-xs">{{ $h['booking']->code }}</td>
                        <td class="px-4 py-2">{{ $h['booking']->customer?->name }}</td>
                        <td class="px-4 py-2">{{ $h['booking']->provider?->user?->name }}</td>
                        <td class="px-4 py-2">{{ $h['days'] }} / {{ $h['limit'] }}</td>
                        <td class="px-4 py-2">{!! $h['unlocked'] ? '<span class="text-green-700">Yes</span>' : '<span class="text-gray-500">Not yet</span>' !!}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">No bookings are held for spares.</td></tr>
                @endforelse
            </tbody>
        </x-ui.table>

        <h2 class="mb-2 text-sm font-semibold">Cancellation charges pending payment ({{ $pending->count() }})</h2>
        <x-ui.table class="mb-6">
            <thead class="bg-gray-50 text-left text-gray-500"><tr><th class="px-4 py-2">Booking</th><th class="px-4 py-2">Customer</th><th class="px-4 py-2">Charge</th><th class="px-4 py-2">Status</th><th class="px-4 py-2">Due by</th><th class="px-4 py-2">Waive (reason required)</th></tr></thead>
            <tbody>
                @forelse ($pending as $r)
                    <tr class="border-t align-top">
                        <td class="px-4 py-2 font-mono text-xs">{{ $r->booking?->code }}</td>
                        <td class="px-4 py-2">{{ $r->booking?->customer?->name }}</td>
                        <td class="px-4 py-2">₹{{ number_format((float) $r->total_charge, 2) }}</td>
                        <td class="px-4 py-2">{{ $r->status === 'awaiting_admin' ? 'Flagged for admin' : 'Awaiting customer' }}</td>
                        <td class="px-4 py-2">{{ $r->due_by?->format('d M Y') }}</td>
                        <td class="px-4 py-2">
                            <div class="flex flex-wrap gap-2">
                                <input type="text" wire:model="waiveReasons.{{ $r->id }}" placeholder="Reason" class="min-w-0 flex-1 border rounded px-2 py-1 text-sm">
                                <button type="button" wire:click="waive({{ $r->id }})" wire:confirm="Waive this charge? The reason is logged." class="rounded border px-2 py-1 text-xs hover:bg-gray-50">Waive</button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400">No unpaid cancellation charges.</td></tr>
                @endforelse
            </tbody>
        </x-ui.table>

        <h2 class="mb-2 text-sm font-semibold">Disputes awaiting resolution ({{ $disputes->count() }})</h2>
        <x-ui.table>
            <thead class="bg-gray-50 text-left text-gray-500"><tr><th class="px-4 py-2">Booking</th><th class="px-4 py-2">Customer</th><th class="px-4 py-2">Declared amount</th><th class="px-4 py-2">Customer's note</th><th class="px-4 py-2">Resolve</th></tr></thead>
            <tbody>
                @forelse ($disputes as $d)
                    <tr class="border-t align-top">
                        <td class="px-4 py-2 font-mono text-xs">{{ $d->code }}</td>
                        <td class="px-4 py-2">{{ $d->customer?->name }}</td>
                        <td class="px-4 py-2">{{ number_format((float) $d->interim_amount, 2) }}</td>
                        <td class="px-4 py-2 text-xs text-gray-600">{{ $d->interim_dispute_note }}</td>
                        <td class="px-4 py-2">
                            <div class="flex flex-wrap gap-2">
                                <input type="text" wire:model="resolutions.{{ $d->id }}" placeholder="How was it resolved?" class="min-w-0 flex-1 border rounded px-2 py-1 text-sm">
                                <button type="button" wire:click="resolveDispute({{ $d->id }})" class="rounded border px-2 py-1 text-xs hover:bg-gray-50">Resolve</button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">No disputes waiting.</td></tr>
                @endforelse
            </tbody>
        </x-ui.table>
    @endif

    {{-- ═══════════════════════ Audit log ═══════════════════════ --}}
    @if ($tab === 'audit')
        <x-ui.table>
            <thead class="bg-gray-50 text-left text-gray-500"><tr><th class="px-4 py-2">When</th><th class="px-4 py-2">Admin</th><th class="px-4 py-2">Key</th><th class="px-4 py-2">Scope</th><th class="px-4 py-2">Old</th><th class="px-4 py-2">New</th></tr></thead>
            <tbody>
                @forelse ($audit as $row)
                    @php($p = $row->properties ?? [])
                    <tr class="border-t">
                        <td class="px-4 py-2 whitespace-nowrap">{{ $row->created_at?->format('d M Y H:i') }}</td>
                        <td class="px-4 py-2">{{ $row->causer?->name ?? '—' }}</td>
                        <td class="px-4 py-2 font-mono text-xs">{{ $p['key'] ?? '' }}</td>
                        <td class="px-4 py-2 text-xs">{{ $p['scope_type'] ?? '' }}{{ ! empty($p['scope_id']) ? ' #'.$p['scope_id'] : '' }}</td>
                        <td class="px-4 py-2">{{ $p['old'] ?? 'unset' }}</td>
                        <td class="px-4 py-2">{{ $p['new'] ?? 'unset' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400">No changes yet.</td></tr>
                @endforelse
            </tbody>
        </x-ui.table>
    @endif
</div>
