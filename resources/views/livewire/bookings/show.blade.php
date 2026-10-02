<div>
    <div class="flex items-center justify-between mb-4">
        <div>
            <a href="{{ route('admin.bookings.index') }}" class="text-sm text-blue-600 hover:underline">&larr; Back to Bookings</a>
            <h1 class="text-2xl font-bold font-mono mt-1">{{ $booking->code }}</h1>
        </div>
        <x-ui.status-badge type="booking" :status="$booking->status" size="lg" />
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
                    @php
                        $cancelDocs = app(\App\Services\Documents\CancellationDocumentService::class);
                    @endphp
                    @if ($cancelDocs->chargeCollected($booking))
                        <div class="flex justify-between"><dt class="text-gray-500">Cancellation invoice</dt><dd><a class="underline" target="_blank" href="{{ route('admin.documents.bookings.cancellation', [$booking->id, 'invoice']) }}">View PDF</a></dd></div>
                    @endif
                    @if ($cancelDocs->refundedPayment($booking))
                        <div class="flex justify-between"><dt class="text-gray-500">Credit note</dt><dd><a class="underline" target="_blank" href="{{ route('admin.documents.bookings.cancellation', [$booking->id, 'credit-note']) }}">View PDF</a></dd></div>
                    @endif
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
                    <thead class="text-left text-gray-500"><tr>
                        <x-ui.sno-th class="py-1" /><th class="py-1">Type</th><th class="py-1">Amount</th></tr></thead>
                    <tbody>
                        @foreach ($booking->compensations as $c)
                            <tr class="border-t">
                                <x-ui.sno :rows="$booking->compensations" :loop="$loop" class="py-1.5" /><td class="py-1.5 capitalize">{{ $c->type }}</td><td class="py-1.5">{{ $this->currencySymbol }}{{ number_format($c->amount, 2) }}</td></tr>
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
                    <tr>
                        <x-ui.sno-th class="py-1" /><th class="py-1">Description</th><th class="py-1">Amount</th><th class="py-1">Status</th></tr>
                </thead>
                <tbody>
                    @foreach ($booking->extraItems as $item)
                        <tr class="border-t">
                            <x-ui.sno :rows="$booking->extraItems" :loop="$loop" class="py-1.5" />
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
                    <tr>
                        <x-ui.sno-th class="py-1" /><th class="py-1">Provider</th><th class="py-1">Status</th><th class="py-1">Distance</th><th class="py-1">Notified</th></tr>
                </thead>
                <tbody>
                    @foreach ($booking->dispatchAttempts as $attempt)
                        <tr class="border-t">
                            <x-ui.sno :rows="$booking->dispatchAttempts" :loop="$loop" class="py-1.5" />
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
                <div class="font-semibold mb-2">{{ $booking->status === 'on_hold' && $booking->hold_category === 'provider_side' ? 'Hand the job to another professional' : 'Manually Assign / Reassign Provider' }}</div>
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

            @if (in_array($booking->status, ['in_progress', 'on_hold'], true))
                <x-ui.card>
                    <div class="font-semibold mb-2">Job controls</div>
                    @if ($booking->status === 'on_hold')
                        <p class="mb-2 text-sm text-gray-600">
                            On hold: <strong>{{ \App\Support\Journey\JourneyCatalog::HOLD_REASONS[$booking->hold_reason] ?? 'on hold' }}</strong>
                            @if ($booking->hold_note) — {{ $booking->hold_note }} @endif
                        </p>
                        <div class="flex flex-wrap gap-2">
                            @if ($booking->hold_reason === 'awaiting_spares')
                                <x-ui.button variant="secondary" wire:click="markSparesAvailable">Spares available</x-ui.button>
                            @endif
                            <x-ui.button wire:click="resumeJob">Resume job</x-ui.button>
                        </div>
                    @else
                        <div class="flex flex-wrap gap-2">
                            <select wire:model="holdReason" class="border rounded px-3 py-2 text-sm" aria-label="Hold reason">
                                @foreach (\App\Support\Journey\JourneyCatalog::HOLD_REASONS as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <input type="text" wire:model="holdNote" placeholder="Note (optional)" class="flex-1 min-w-[10rem] border rounded px-3 py-2 text-sm">
                            <x-ui.button variant="secondary" class="whitespace-nowrap" wire:click="holdJob">Put on hold</x-ui.button>
                        </div>
                        <div class="mt-3 border-t pt-3">
                            <p class="mb-2 text-sm text-gray-600">Did the professional leave the site mid-work?</p>
                            <x-ui.button variant="danger" wire:click="flagProviderLeft" wire:confirm="Flag this job as: professional left? The customer will be told and you can then assign someone else or cancel with no fee.">Professional left</x-ui.button>
                        </div>
                    @endif
                </x-ui.card>
            @endif

            {{-- REF 1CF-CANCEL-POLICY-001 — interim-work declaration, its evidence, dispute review, unpaid-charge waiver --}}
            @php $cancelRequests = $booking->cancellationRequests()->latest('id')->get(); @endphp
            @if ($booking->interim_declared_at || $cancelRequests->isNotEmpty())
                <x-ui.card>
                    <div class="font-semibold mb-2">Spares delay &amp; cancellation</div>
                    @if ($booking->interim_declared_at)
                        <dl class="grid gap-1 text-sm sm:grid-cols-4">
                            <div><dt class="text-xs text-gray-500">Declared progress</dt><dd class="font-medium">{{ $booking->interim_progress_percent }}%</dd></div>
                            <div><dt class="text-xs text-gray-500">Parts fitted</dt><dd class="font-medium">{{ number_format((float) $booking->interim_parts_cost, 2) }}</dd></div>
                            <div><dt class="text-xs text-gray-500">Part expected</dt><dd class="font-medium">{{ $booking->spares_expected_at?->format('j M Y') ?? '—' }}</dd></div>
                            <div><dt class="text-xs text-gray-500">Sourced by</dt><dd class="font-medium capitalize">{{ $booking->spares_sourced_by ?? '—' }}</dd></div>
                        </dl>
                        @if (! empty($booking->interim_evidence))
                            <p class="mt-2 text-sm">Evidence:
                                @foreach ($booking->interim_evidence as $i => $path)
                                    <a href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($path) }}" target="_blank" rel="noopener" class="text-blue-700 underline">file {{ $i + 1 }}</a>@if (! $loop->last), @endif
                                @endforeach
                            </p>
                        @endif
                    @endif

                    @if ($booking->interim_dispute_status === 'open')
                        <div class="mt-3 rounded border border-amber-300 bg-amber-50 p-3 text-sm">
                            <p class="font-medium text-amber-900">Customer disputes these figures</p>
                            <p class="mt-1 text-amber-900">{{ $booking->interim_dispute_note }}</p>
                            <p class="mt-1 text-xs text-amber-800">Review the evidence above and the status history below. The customer cannot cancel until this is resolved.</p>
                            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                <input type="number" min="0" max="100" wire:model="disputeProgress" placeholder="Corrected progress % (optional)" class="border rounded px-3 py-2 text-sm">
                                <input type="number" step="0.01" min="0" wire:model="disputeParts" placeholder="Corrected parts cost (optional)" class="border rounded px-3 py-2 text-sm">
                            </div>
                            <input type="text" wire:model="disputeResolution" placeholder="Resolution (required)" class="mt-2 w-full border rounded px-3 py-2 text-sm">
                            <x-ui.button class="mt-2" wire:click="resolveDispute">Resolve dispute</x-ui.button>
                        </div>
                    @endif

                    @foreach ($cancelRequests as $req)
                        <div class="mt-3 rounded border p-3 text-sm">
                            <p><strong>Cancellation request</strong> · {{ number_format((float) $req->total_charge, 2) }} · <span class="capitalize">{{ str_replace('_', ' ', $req->status) }}</span>@if ($req->due_by && $req->status === 'awaiting_payment') · due {{ $req->due_by->format('j M Y') }}@endif</p>
                            @if ($req->resolution_note)<p class="text-xs text-gray-500">{{ $req->resolution_note }}</p>@endif
                            @if (in_array($req->status, ['awaiting_payment', 'awaiting_admin'], true))
                                <div class="mt-2 flex gap-2">
                                    <input type="text" wire:model="waiveReason" placeholder="Reason for waiving (logged)" class="flex-1 border rounded px-3 py-2 text-sm">
                                    <x-ui.button variant="secondary" class="whitespace-nowrap" wire:click="waiveCancellationCharge({{ $req->id }})" wire:confirm="Waive this charge and cancel the booking?">Waive &amp; cancel</x-ui.button>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </x-ui.card>
            @endif

            <x-ui.card>
                <div class="font-semibold mb-2 text-red-700">Cancel Booking</div>
                <div class="flex gap-2">
                    <input type="text" wire:model="cancelReason" placeholder="Reason for cancellation..."
                           class="flex-1 border rounded px-3 py-2 text-sm">
                    <x-ui.button variant="danger" class="whitespace-nowrap" wire:click="cancel">Cancel</x-ui.button>
                </div>
                @if ($booking->hold_category === 'provider_side')
                    <p class="mt-2 text-xs text-gray-500">The professional left — no cancellation fee will be charged.</p>
                @elseif ($booking->provider_id)
                    <label class="mt-2 flex items-center gap-2 text-sm text-gray-600"><input type="checkbox" wire:model="waiveFee"> Waive the cancellation fee</label>
                @endif
            </x-ui.card>
        </div>
    @endif

    @php($journey = \App\Support\Journey\JourneyBuilder::build('service', $booking->status, $booking->statusHistory, \App\Support\Journey\JourneyContext::forBooking($booking)))
    @if ($journey)
        <x-ui.card class="mb-4">
            <x-journey.timeline :journey="$journey" :franchise="$booking->franchise" variant="compact" />
        </x-ui.card>
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
