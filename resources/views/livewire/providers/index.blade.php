<div>
    <h1 class="text-2xl font-bold mb-4">Providers</h1>

    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <div class="flex gap-2">
            @foreach (['pending' => 'Pending Review', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $key => $label)
                <button wire:click="$set('statusFilter', '{{ $key }}')"
                        class="px-3 py-1.5 rounded text-sm {{ $statusFilter === $key ? 'bg-slate-900 text-white' : 'bg-white border' }}">
                    {{ $label }}
                    @if(isset($counts[$key]))
                        <span class="opacity-60">({{ $counts[$key] }})</span>
                    @endif
                </button>
            @endforeach
        </div>

        <input type="text" wire:model.live.debounce.400ms="search" placeholder="Search by name or phone…"
               class="border rounded px-3 py-2 text-sm w-64">
    </div>

    @if ($statusFilter === 'pending' && $providers->total() > 0)
        {{-- Admin Polish + AI session, Part 1 item 3 — makes the queue
             framing explicit rather than implicit in the sort order alone. --}}
        <p class="text-xs text-gray-500 mb-3">
            <x-icon name="clock" class="w-3.5 h-3.5 inline" />
            Oldest applications first — {{ $providers->total() }} waiting for review.
        </p>
    @endif

    <x-ui.table>
        <x-slot:footer>{{ $providers->links() }}</x-slot:footer>

        <thead class="bg-gray-50 text-left text-gray-500">
            <tr>
                <th class="px-4 py-2">Name</th>
                <th class="px-4 py-2">Phone</th>
                <th class="px-4 py-2">Zone</th>
                <th class="px-4 py-2">Type</th>
                <th class="px-4 py-2">Status</th>
                <th class="px-4 py-2">Documents</th>
                <th class="px-4 py-2">{{ $statusFilter === 'pending' ? 'Waiting' : 'Applied' }}</th>
                <th class="px-4 py-2"></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($providers as $provider)
                <tr class="border-t hover:bg-gray-50">
                    <td class="px-4 py-2">{{ $provider->user->name ?? '—' }}</td>
                    <td class="px-4 py-2">{{ $provider->user->phone ?? '—' }}</td>
                    <td class="px-4 py-2">{{ $provider->zone->name ?? '—' }}</td>
                    <td class="px-4 py-2">{{ ucfirst($provider->provider_type) }}</td>
                    <td class="px-4 py-2"><x-ui.status-badge type="provider_kyc" :status="$provider->kyc_status" /></td>
                    <td class="px-4 py-2">{{ $provider->documents->count() }} uploaded</td>
                    <td class="px-4 py-2 text-gray-500">{{ $provider->created_at->diffForHumans() }}</td>
                    <td class="px-4 py-2">
                        <x-ui.button variant="ghost" :href="route('admin.providers.show', $provider->id)">Review</x-ui.button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8">
                    <x-ui.empty-state icon="check-circle" :title="$statusFilter === 'pending' ? 'Queue is clear' : 'No providers in this status'" :description="$statusFilter === 'pending' ? 'No pending applications need review right now.' : null" />
                </td></tr>
            @endforelse
        </tbody>
    </x-ui.table>
</div>
