<div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-2xl font-bold">Workers</h1>
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

    <x-ui.filter-tabs class="mb-4"
                      :tabs="['' => 'All', 'pending' => 'Pending Review', 'approved' => 'Approved', 'rejected' => 'Rejected', 'archived' => 'Archived']"
                      :active="$statusFilter" model="statusFilter" :counts="$counts" />

    <x-ui.table>
        <x-slot:footer>{{ $workers->links() }}</x-slot:footer>

        <thead class="bg-gray-50 text-left text-gray-500">
            <tr>
                <x-ui.sno-th />
                <th class="px-4 py-2">Name</th>
                <th class="px-4 py-2">Phone</th>
                <th class="px-4 py-2">Zone</th>
                <th class="px-4 py-2">Capabilities</th>
                <th class="px-4 py-2">Documents</th>
                <th class="px-4 py-2">Applied</th>
                <th class="px-4 py-2"></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($workers as $worker)
                <tr class="border-t hover:bg-gray-50">
                    <x-ui.sno :rows="$workers" :loop="$loop" />
                    <td class="px-4 py-2">{{ $worker->user->name ?? '—' }}</td>
                    <td class="px-4 py-2">{{ $worker->user->phone ?? '—' }}</td>
                    <td class="px-4 py-2">{{ $worker->zone->name ?? '—' }}</td>
                    <td class="px-4 py-2 text-gray-500">
                        @forelse ($worker->capabilities as $cap)
                            <span class="inline-block bg-gray-100 rounded px-1.5 py-0.5 text-xs mr-1">{{ str_replace('_', ' ', $cap->capability_type) }}</span>
                        @empty
                            —
                        @endforelse
                    </td>
                    <td class="px-4 py-2">{{ $worker->documents->count() }} uploaded</td>
                    <td class="px-4 py-2 text-gray-500">{{ $worker->created_at->diffForHumans() }}</td>
                    <td class="px-4 py-2">
                        <x-ui.row-actions :id="$worker->id" :archived="$worker->trashed()" :can-manage="$canManage" :can-force="$canForce"
                                          :view-href="route('admin.workers.show', $worker->id)" view-label="Review"
                                          :edit-href="route('admin.workers.show', $worker->id)" />
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-6 text-center text-gray-400">No workers in this status.</td></tr>
            @endforelse
        </tbody>
    </x-ui.table>
</div>
