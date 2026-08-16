<div>
    <div class="flex items-center justify-between mb-4">
        <div>
            <a href="{{ route('admin.bookings.index') }}" class="text-sm text-blue-600 hover:underline">&larr; Back to Bookings</a>
            <h1 class="text-2xl font-bold font-mono mt-1">{{ $booking->code }}</h1>
        </div>
        <x-ui.badge size="lg" :color="match(true) {
            $booking->status === 'pending' => 'gray',
            in_array($booking->status, ['searching_provider','assigned','provider_en_route']) => 'blue',
            in_array($booking->status, ['in_progress','on_hold']) => 'amber',
            $booking->status === 'completed' => 'green',
            in_array($booking->status, ['cancelled','disputed']) => 'red',
            default => 'gray',
        }">{{ str_replace('_', ' ', $booking->status) }}</x-ui.badge>
    </div>

    @if ($flashMessage)
        <div @class([
            'rounded p-3 mb-4 text-sm',
            'bg-green-50 text-green-700' => $flashType === 'success',
            'bg-red-50 text-red-700' => $flashType === 'error',
        ])>
            {{ $flashMessage }}
        </div>
    @endif

    @if ($booking->status === 'on_hold')
        <div class="bg-amber-50 border border-amber-200 rounded p-4 mb-4">
            <div class="font-semibold text-amber-800">On Hold</div>
            <div class="text-sm text-amber-700">Category: {{ $booking->hold_category }} — Reason: {{ str_replace('_', ' ', $booking->hold_reason) }}</div>
            @if ($booking->hold_note)
                <div class="text-sm text-amber-600 mt-1">{{ $booking->hold_note }}</div>
            @endif
        </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
        <x-ui.card>
            <div class="font-semibold mb-2">Customer & Service</div>
            <dl class="text-sm space-y-1">
                <div class="flex justify-between"><dt class="text-gray-500">Customer</dt><dd>{{ $booking->customer->name ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Phone</dt><dd>{{ $booking->customer->phone ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Service</dt><dd>{{ $booking->service->name ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Address</dt><dd class="text-right">{{ $booking->address->address_line ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Franchise / Zone</dt><dd>{{ $booking->franchise->name ?? '—' }} / {{ $booking->zone->name ?? '—' }}</dd></div>
            </dl>
        </x-ui.card>

        <x-ui.card>
            <div class="font-semibold mb-2">Provider & Payment</div>
            <dl class="text-sm space-y-1">
                <div class="flex justify-between"><dt class="text-gray-500">Provider</dt><dd>{{ $booking->provider?->user?->name ?? '— unassigned —' }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Assigned worker</dt><dd>{{ $booking->assignedWorker?->user?->name ?? '— provider performing personally —' }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Price quoted</dt><dd>{{ $this->currencySymbol }}{{ number_format($booking->price_quoted, 2) }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Price final</dt><dd>{{ $booking->price_final ? $this->currencySymbol.number_format($booking->price_final, 2) : '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Payment status</dt><dd>{{ $booking->payment_status }}</dd></div>
                @if (! is_null($booking->cancellation_fee))
                    <div class="flex justify-between"><dt class="text-gray-500">Cancellation fee</dt><dd>{{ $this->currencySymbol }}{{ number_format($booking->cancellation_fee, 2) }}</dd></div>
                @endif
                @if ($booking->commission)
                    <div class="flex justify-between"><dt class="text-gray-500">Provider commission</dt><dd>{{ $this->currencySymbol }}{{ number_format($booking->commission->provider_commission, 2) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Platform commission</dt><dd>{{ $this->currencySymbol }}{{ number_format($booking->commission->platform_commission, 2) }}</dd></div>
                @endif
            </dl>
        </x-ui.card>
    </div>

    @if ($booking->status === 'completed')
        <x-ui.card class="mb-6">
            <div class="font-semibold mb-2">Compensation</div>
            @if ($booking->compensations->isNotEmpty())
                <table class="w-full text-sm mb-3">
                    <thead class="text-left text-gray-500"><tr><th class="py-1">Type</th><th class="py-1">Amount</th></tr></thead>
                    <tbody>
                        @foreach ($booking->compensations as $c)
                            <tr class="border-t"><td class="py-1.5 capitalize">{{ $c->type }}</td><td class="py-1.5">{{ $this->currencySymbol }}{{ number_format($c->amount, 2) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
            <div class="flex items-end gap-2">
                <div>
                    <label class="block text-xs font-medium mb-1">Apply (manual only)</label>
                    <select wire:model="compensationType" class="border rounded px-2 py-1.5 text-sm"><option value="rain">Rain</option><option value="waiting">Waiting</option></select>
                </div>
                @if ($compensationType === 'waiting')
                    <div><label class="block text-xs font-medium mb-1">Minutes</label><input type="number" wire:model="compensationMinutes" class="w-24 border rounded px-2 py-1.5 text-sm"></div>
                @endif
                <x-ui.button size="sm" wire:click="applyCompensation">Apply</x-ui.button>
            </div>
        </x-ui.card>
    @endif

    @if ($booking->extraItems->isNotEmpty())
        <x-ui.card class="mb-6">
            <div class="font-semibold mb-2">Extra Work Items</div>
            <table class="w-full text-sm">
                <thead class="text-left text-gray-500">
                    <tr><th class="py-1">Description</th><th class="py-1">Amount</th><th class="py-1">Status</th></tr>
                </thead>
                <tbody>
                    @foreach ($booking->extraItems as $item)
                        <tr class="border-t">
                            <td class="py-1.5">{{ $item->description }}</td>
                            <td class="py-1.5">{{ $this->currencySymbol }}{{ number_format($item->amount, 2) }}</td>
                            <td class="py-1.5">
                                <x-ui.badge :color="match($item->status) { 'pending_approval' => 'amber', 'approved' => 'green', 'rejected' => 'red', default => 'gray' }">{{ $item->status }}</x-ui.badge>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.card>
    @endif

    @if ($booking->dispatchAttempts->isNotEmpty())
        <x-ui.card class="mb-6">
            <div class="font-semibold mb-2">Dispatch Attempts</div>
            <table class="w-full text-sm">
                <thead class="text-left text-gray-500">
                    <tr><th class="py-1">Provider</th><th class="py-1">Status</th><th class="py-1">Distance</th><th class="py-1">Notified</th></tr>
                </thead>
                <tbody>
                    @foreach ($booking->dispatchAttempts as $attempt)
                        <tr class="border-t">
                            <td class="py-1.5">{{ $attempt->provider?->user?->name ?? '#'.$attempt->provider_id }}</td>
                            <td class="py-1.5">{{ $attempt->status }}</td>
                            <td class="py-1.5">{{ $attempt->distance_km }} km</td>
                            <td class="py-1.5 text-gray-500">{{ app(\App\Services\TimezoneResolver::class)->format($attempt->notified_at, $booking->franchise, 'Y-m-d H:i:s') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.card>
    @endif

    @if (!in_array($booking->status, ['completed', 'cancelled']))
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
            <x-ui.card>
                <div class="font-semibold mb-2">Manually Assign / Reassign Provider</div>
                <div class="flex gap-2">
                    <select wire:model="selectedProviderId" class="flex-1 border rounded px-3 py-2 text-sm">
                        <option value="">Select a provider...</option>
                        @foreach ($this->availableProviders as $p)
                            <option value="{{ $p->id }}">{{ $p->user->name ?? 'Provider #'.$p->id }} ({{ $p->is_online ? 'online' : 'offline' }})</option>
                        @endforeach
                    </select>
                    <x-ui.button class="whitespace-nowrap" wire:click="reassign">Assign</x-ui.button>
                </div>
            </x-ui.card>

            @if ($this->canAssignWorker())
                <x-ui.card>
                    <div class="font-semibold mb-2">Assign to a Field Worker</div>
                    @if ($this->availableWorkers->isEmpty())
                        <p class="text-sm text-gray-400">This provider has no active workers on their team yet.</p>
                    @else
                        <div class="flex gap-2">
                            <select wire:model="selectedWorkerId" class="flex-1 border rounded px-3 py-2 text-sm">
                                <option value="">Select a worker...</option>
                                @foreach ($this->availableWorkers as $w)
                                    <option value="{{ $w->id }}">{{ $w->user->name ?? 'Worker #'.$w->id }} ({{ $w->is_online ? 'online' : 'offline' }})</option>
                                @endforeach
                            </select>
                            <x-ui.button class="whitespace-nowrap" wire:click="assignWorker">Assign</x-ui.button>
                        </div>
                    @endif
                </x-ui.card>
            @endif

            <x-ui.card>
                <div class="font-semibold mb-2 text-red-700">Cancel Booking</div>
                <div class="flex gap-2">
                    <input type="text" wire:model="cancelReason" placeholder="Reason for cancellation..."
                           class="flex-1 border rounded px-3 py-2 text-sm">
                    <x-ui.button variant="danger" class="whitespace-nowrap" wire:click="cancel">Cancel</x-ui.button>
                </div>
            </x-ui.card>
        </div>
    @endif

    <x-ui.card>
        <div class="font-semibold mb-2">Status History</div>
        <div class="space-y-2 text-sm">
            @foreach ($booking->statusHistory as $entry)
                <div class="flex gap-3 border-t pt-2 first:border-t-0 first:pt-0">
                    <div class="text-gray-500 w-40 shrink-0">{{ app(\App\Services\TimezoneResolver::class)->format($entry->changed_at, $booking->franchise, 'Y-m-d H:i:s') }}</div>
                    <div>
                        <span class="font-medium">{{ str_replace('_', ' ', $entry->status) }}</span>
                        @if ($entry->note) — <span class="text-gray-600">{{ $entry->note }}</span> @endif
                    </div>
                </div>
            @endforeach
        </div>
    </x-ui.card>
</div>
