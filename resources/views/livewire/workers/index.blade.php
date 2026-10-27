<div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        @unless ($embedded)<h1 class="text-2xl font-bold">Workers</h1>@else<span></span>@endunless
        @if ($canManage)
            <x-ui.button wire:click="$toggle('showAddRider')">{{ $showAddRider ? 'Close' : 'Add parcel rider' }}</x-ui.button>
        @endif
    </div>

    @if ($riderMessage)
        <div class="mb-4 bg-green-50 text-green-700 text-sm px-4 py-2 rounded">{{ $riderMessage }}</div>
    @endif

    {{-- Parcel delivery: the only way to create a rider (CreateParcelRiderAction). --}}
    @if ($canManage && $showAddRider)
        <x-ui.card class="mb-4">
            <h2 class="font-semibold mb-1">Add parcel rider</h2>
            <p class="text-xs text-gray-500 mb-3">An existing account with this mobile number is linked and keeps its own password; otherwise a new account is created with the password below. The rider signs in at <span class="font-mono">{{ route('rider.login') }}</span>.</p>
            <form wire:submit="addRider" class="grid gap-3 sm:grid-cols-2 text-sm">
                <label class="block"><span class="block text-xs font-medium mb-1">Name</span>
                    <input type="text" wire:model="riderName" class="w-full border rounded px-2 py-2">
                    @error('riderName') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
                <label class="block"><span class="block text-xs font-medium mb-1">Mobile number</span>
                    <input type="tel" wire:model="riderPhone" class="w-full border rounded px-2 py-2">
                    @error('riderPhone') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
                <label class="block"><span class="block text-xs font-medium mb-1">Email (optional)</span>
                    <input type="email" wire:model="riderEmail" class="w-full border rounded px-2 py-2">
                    @error('riderEmail') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
                <label class="block"><span class="block text-xs font-medium mb-1">Password (new accounts, min 8)</span>
                    <input type="text" wire:model="riderPassword" autocomplete="new-password" class="w-full border rounded px-2 py-2">
                    @error('riderPassword') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
                <label class="block"><span class="block text-xs font-medium mb-1">Zone</span>
                    <select wire:model="riderZoneId" class="w-full border rounded px-2 py-2">
                        <option value="">Choose a zone</option>
                        @foreach ($riderZones as $z)<option value="{{ $z->id }}">{{ $z->name }}</option>@endforeach
                    </select>
                    @error('riderZoneId') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </label>
                <label class="flex items-start gap-2 sm:col-span-2">
                    <input type="checkbox" wire:model="riderVerified" class="mt-0.5">
                    <span>I have checked this rider's ID and driving licence in person. <span class="text-gray-500">(Recorded in the activity log with your name.)</span></span>
                </label>
                @error('riderVerified') <span class="text-xs text-red-600 sm:col-span-2">{{ $message }}</span> @enderror
                <div class="sm:col-span-2"><x-ui.button type="submit">Add rider</x-ui.button></div>
            </form>
        </x-ui.card>
    @endif

    <x-ui.archive-bars :bars="$archiveBars" />

    <x-ui.filter-tabs class="mb-4" :tabs="$statusTabs" :active="$statusFilter" model="statusFilter" :counts="$counts" :alerts="['review' => $counts['overdue'] ?? 0]" />

    @include('livewire.partners.partials.filters', ['typeLabel' => 'All types'])

    <x-ui.table>
        <x-slot:footer>{{ $workers->links() }}</x-slot:footer>

        <thead class="bg-gray-50 text-left text-gray-500">
            <tr>
                <x-ui.sno-th />
                <th class="px-4 py-2">Name</th>
                <th class="px-4 py-2">Phone</th>
                <th class="px-4 py-2">City / zone</th>
                <th class="px-4 py-2">Capabilities</th>
                <th class="px-4 py-2">Documents</th>
                <th class="px-4 py-2">{{ $statusFilter === 'review' ? 'Waiting' : 'Applied' }}</th>
                <th class="px-4 py-2"></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($workers as $worker)
                @php $overdue = $statusFilter === 'review' && \App\Support\PartnerReview::level(\App\Support\PartnerReview::hours($worker->waiting_since)) === 'red'; @endphp
                <tr @class(['border-t', 'border-l-4 border-l-red-600 bg-red-50 hover:bg-red-100' => $overdue, 'hover:bg-gray-50' => ! $overdue])>
                    <x-ui.sno :rows="$workers" :loop="$loop" />
                    <td class="px-4 py-2">{{ $worker->user->name ?? '—' }}</td>
                    <td class="px-4 py-2">{{ $worker->user->phone ?? '—' }}</td>
                    <td class="px-4 py-2">{{ $worker->franchise?->city?->name ?? '—' }}<span class="block text-xs text-gray-500">{{ $worker->zone->name ?? '' }}</span></td>
                    <td class="px-4 py-2 text-gray-500">
                        @forelse ($worker->capabilities as $cap)
                            <span class="inline-block bg-gray-100 rounded px-1.5 py-0.5 text-xs mr-1">{{ str_replace('_', ' ', $cap->capability_type) }}</span>
                        @empty
                            —
                        @endforelse
                    </td>
                    <td class="px-4 py-2">{{ $worker->documents->count() }} uploaded</td>
                    <td class="px-4 py-2 text-gray-500">@include('livewire.partners.partials.waiting', ['row' => $worker])</td>
                    <td class="px-4 py-2">
                        <x-ui.row-actions :id="$worker->id" :archived="$worker->trashed()" :can-manage="$canManage" :can-force="$canForce"
                                          :view-href="route('admin.workers.show', $worker->id)" view-label="Review"
                                          :edit-href="route('admin.workers.show', $worker->id)" />
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-6 text-center text-gray-400">No partners match these filters.</td></tr>
            @endforelse
        </tbody>
    </x-ui.table>
</div>
