<div>
    <h1 class="text-2xl font-bold mb-4">Providers</h1>

    <div class="flex gap-2 mb-4">
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

    <x-ui.table>
        <x-slot:footer>{{ $providers->links() }}</x-slot:footer>

        <thead class="bg-gray-50 text-left text-gray-500">
            <tr>
                <th class="px-4 py-2">Name</th>
                <th class="px-4 py-2">Phone</th>
                <th class="px-4 py-2">Zone</th>
                <th class="px-4 py-2">Type</th>
                <th class="px-4 py-2">Documents</th>
                <th class="px-4 py-2">Applied</th>
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
                    <td class="px-4 py-2">{{ $provider->documents->count() }} uploaded</td>
                    <td class="px-4 py-2 text-gray-500">{{ $provider->created_at->diffForHumans() }}</td>
                    <td class="px-4 py-2">
                        <x-ui.button variant="ghost" :href="route('admin.providers.show', $provider->id)">Review</x-ui.button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-6 text-center text-gray-400">No providers in this status.</td></tr>
            @endforelse
        </tbody>
    </x-ui.table>
</div>
