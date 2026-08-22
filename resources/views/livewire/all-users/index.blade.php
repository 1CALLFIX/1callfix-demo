<div>
    <div class="flex items-center justify-between mb-4">
        <div>
            <h1 class="text-2xl font-bold">All Users</h1>
            <p class="text-xs text-gray-400">Customers, Providers, Workers, and staff in one place — Customers/Providers/Workers keep their own dedicated screens for type-specific actions (KYC review, etc.); this is search + bulk messaging.</p>
        </div>
        <div class="flex gap-2 text-sm">
            <x-ui.button variant="secondary" size="sm" wire:click="exportUsersCsv" title="Export the current filtered view as CSV">Export CSV</x-ui.button>
            <x-ui.button size="sm" wire:click="openNotifyModal" :disabled="empty($selectedIds)">
                Send Notification @if(count($selectedIds)) ({{ count($selectedIds) }}) @endif
            </x-ui.button>
        </div>
    </div>

    @if ($notifyFlashMessage && ! $showNotifyModal)
        <div @class(['rounded p-3 mb-4 text-sm', 'bg-green-50 text-green-700' => $notifyFlashType === 'success', 'bg-red-50 text-red-700' => $notifyFlashType === 'error'])>
            {{ $notifyFlashMessage }}
        </div>
    @endif

    @error('permission') <div class="bg-red-50 text-red-700 rounded p-3 mb-4 text-sm">{{ $message }}</div> @enderror

    {{-- Growth chart (Part 3, best effort) — same inline-SVG bar technique as Dashboard's own 7-day trend, respecting the current filters. --}}
    <div class="bg-white rounded-lg shadow-sm p-4 mb-4">
        <div class="text-sm font-semibold text-gray-500 uppercase mb-3">New Signups — Last 7 Days {{ $typeFilter ? '('.ucfirst($typeFilter).')' : '' }}</div>
        <div class="flex items-end gap-2 h-20" role="img" aria-label="New users per day: {{ collect($growth)->map(fn($d) => $d['label'].' '.$d['count'])->implode(', ') }}">
            @php $max = max(1, collect($growth)->max('count')); @endphp
            @foreach ($growth as $day)
                <div class="flex-1 flex flex-col items-center gap-1">
                    <div class="text-[11px] font-medium text-gray-600">{{ $day['count'] }}</div>
                    <div class="w-full bg-indigo-500 rounded-t" aria-hidden="true" style="height: {{ max(3, ($day['count'] / $max) * 100) }}%"></div>
                    <div class="text-[11px] text-gray-400">{{ $day['label'] }}</div>
                </div>
            @endforeach
        </div>
    </div>

    <div class="flex flex-wrap gap-3 mb-4">
        <input type="text" wire:model.live.debounce.400ms="search" placeholder="Search name, phone, or email..." class="border rounded px-3 py-2 text-sm w-72">
        <select wire:model.live="typeFilter" class="border rounded px-3 py-2 text-sm">
            <option value="">All types</option>
            <option value="customer">Customer</option>
            <option value="provider">Provider</option>
            <option value="worker">Worker</option>
            <option value="staff">Staff / Admin</option>
        </select>
        <select wire:model.live="franchiseIdFilter" class="border rounded px-3 py-2 text-sm">
            <option value="">All franchises</option>
            @foreach ($franchises as $f)
                <option value="{{ $f->id }}">{{ $f->name }}</option>
            @endforeach
        </select>
        <select wire:model.live="zoneIdFilter" class="border rounded px-3 py-2 text-sm">
            <option value="">All zones</option>
            @foreach ($zones as $z)
                <option value="{{ $z->id }}">{{ $z->name }}</option>
            @endforeach
        </select>
        @if (count($selectedIds))
            <button type="button" wire:click="clearSelection" class="text-xs text-gray-400 hover:text-gray-600 self-center">Clear selection ({{ count($selectedIds) }})</button>
        @endif
    </div>

    <x-ui.table>
        <x-slot:footer>{{ $users->links() }}</x-slot:footer>

        <thead class="bg-gray-50 text-left text-gray-500">
            <tr>
                <th class="px-4 py-2 w-8"></th>
                <th class="px-4 py-2">Name</th>
                <th class="px-4 py-2">Phone</th>
                <th class="px-4 py-2">Email</th>
                <th class="px-4 py-2">Type</th>
                <th class="px-4 py-2">Franchise</th>
                <th class="px-4 py-2">Wallet</th>
                <th class="px-4 py-2">Status</th>
                <th class="px-4 py-2">Joined</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($users as $user)
                <tr class="border-t hover:bg-gray-50">
                    <td class="px-4 py-2">
                        <input type="checkbox" wire:click="toggleSelect({{ $user->id }})" @checked(in_array($user->id, $selectedIds)) aria-label="Select {{ $user->name }}">
                    </td>
                    <td class="px-4 py-2">{{ $user->name }}</td>
                    <td class="px-4 py-2">{{ $user->phone }}</td>
                    <td class="px-4 py-2 text-gray-500">{{ $user->email ?? '—' }}</td>
                    <td class="px-4 py-2"><x-ui.badge color="gray">{{ $this->typeLabel($user) }}</x-ui.badge></td>
                    <td class="px-4 py-2 text-gray-500">{{ $user->franchise->name ?? '—' }}</td>
                    <td class="px-4 py-2 font-mono">
                        @if (in_array($user->role, ['customer', 'provider'], true))
                            {{ $currencySymbol }}{{ number_format($user->wallet->balance ?? 0, 2) }}
                        @else
                            —
                        @endif
                    </td>
                    <td class="px-4 py-2"><x-ui.status-badge type="customer" :status="$user->status" /></td>
                    <td class="px-4 py-2 text-gray-500">{{ $user->created_at->diffForHumans() }}</td>
                </tr>
            @empty
                <tr><td colspan="9"><x-ui.empty-state icon="users" title="No users match this filter" /></td></tr>
            @endforelse
        </tbody>
    </x-ui.table>

    {{-- Bulk Notify — compose then confirm, per the mission's explicit
         "a misclick here shouldn't blast real customers" requirement. --}}
    <x-ui.modal :show="$showNotifyModal" title="Send Notification" onClose="closeNotifyModal" maxWidth="lg">
        @if ($notifyFlashType === 'error' && $notifyFlashMessage)
            <div class="bg-red-50 text-red-700 rounded p-3 mb-3 text-sm">{{ $notifyFlashMessage }}</div>
        @endif

        @if ($notifyStep === 'compose')
            <div class="text-xs text-gray-400 mb-3">{{ count($selectedIds) }} recipient(s) selected.</div>

            <div class="mb-3">
                <label class="block text-xs font-medium mb-1">Subject</label>
                <input type="text" wire:model="notifySubject" class="w-full border rounded px-3 py-2 text-sm" maxlength="255">
                @error('notifySubject') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="mb-3">
                <label class="block text-xs font-medium mb-1">Message</label>
                <textarea wire:model="notifyBody" rows="4" class="w-full border rounded px-3 py-2 text-sm" maxlength="5000"></textarea>
                @error('notifyBody') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="mb-1">
                <label class="block text-xs font-medium mb-1">Channels</label>
                <div class="grid grid-cols-2 gap-2">
                    @foreach (['mail' => 'Mail', 'sms' => 'SMS', 'push' => 'Push', 'whatsapp' => 'WhatsApp'] as $key => $label)
                        <label class="flex items-center gap-2 text-sm border rounded px-3 py-2 {{ $channelAvailability[$key] ? 'border-gray-200' : 'border-amber-200 bg-amber-50' }}">
                            <input type="checkbox" wire:model="notifyChannels.{{ $key }}" class="rounded">
                            {{ $label }}
                            @if ($channelAvailability[$key])
                                <span class="text-[10px] text-emerald-600 ml-auto">will send for real</span>
                            @else
                                <span class="text-[10px] text-amber-600 ml-auto">log-only — no real gateway configured</span>
                            @endif
                        </label>
                    @endforeach
                </div>
                @error('notifyChannels') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="flex justify-end pt-4 mt-4 border-t">
                <x-ui.button wire:click="reviewNotify">Review &amp; Continue</x-ui.button>
            </div>
        @else
            {{-- CONFIRM step — "this will message 47 people" before anything actually sends. --}}
            <div class="bg-amber-50 text-amber-800 rounded p-4 mb-4 text-sm">
                This will message <strong>{{ $notifyConfirmedCount }}</strong> {{ \Illuminate\Support\Str::plural('person', $notifyConfirmedCount) }} via
                <strong>{{ implode(', ', array_keys(array_filter($notifyChannels))) }}</strong>. This cannot be undone.
            </div>

            <div class="border rounded p-3 mb-4 text-sm bg-gray-50">
                <div class="font-medium mb-1">{{ $notifySubject }}</div>
                <div class="text-gray-600 whitespace-pre-line">{{ $notifyBody }}</div>
            </div>

            <div class="flex justify-between pt-4 mt-4 border-t">
                <button type="button" wire:click="backToCompose" class="text-sm text-gray-500 hover:text-gray-700">&larr; Back to edit</button>
                <x-ui.button wire:click="confirmSend" wire:loading.attr="disabled" wire:target="confirmSend">
                    <span wire:loading.remove wire:target="confirmSend">Confirm Send</span>
                    <span wire:loading wire:target="confirmSend">Sending…</span>
                </x-ui.button>
            </div>
        @endif
    </x-ui.modal>
</div>
