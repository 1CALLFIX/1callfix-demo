<div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <div>
            <h1 class="text-xl font-semibold">Partner leads</h1>
            <p class="text-sm text-gray-500">People who applied on the public Partner page. Read-only, except the status.</p>
        </div>
        <x-ui.button variant="secondary" size="sm" wire:click="exportCsv" title="Export the current filtered view as CSV">Export CSV</x-ui.button>
    </div>

    @if ($flashMessage)
        <div class="rounded px-4 py-2 mb-4 text-sm bg-green-50 text-green-700">{{ $flashMessage }}</div>
    @endif
    @error('status') <p class="mb-3 text-sm text-red-600">{{ $message }}</p> @enderror

    <div class="mb-4 flex flex-wrap gap-3">
        <select wire:model.live="statusFilter" class="border rounded px-3 py-2 text-sm" aria-label="Filter by status">
            <option value="">All statuses</option>
            @foreach ($statuses as $status)
                <option value="{{ $status }}">{{ str_replace('_', ' ', $status) }}</option>
            @endforeach
        </select>
        <select wire:model.live="roleFilter" class="border rounded px-3 py-2 text-sm" aria-label="Filter by role">
            <option value="">All roles</option>
            @foreach ($roles as $code => $label)
                <option value="{{ $code }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <x-ui.card>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase text-gray-500">
                        <th class="py-2 pr-4">Name</th><th class="py-2 pr-4">Mobile</th><th class="py-2 pr-4">City</th>
                        <th class="py-2 pr-4">Role</th><th class="py-2 pr-4">Status</th><th class="py-2 pr-4">Source</th><th class="py-2 pr-4">Applied</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($leads as $lead)
                        <tr data-lead-row class="border-t">
                            <td class="py-2 pr-4">{{ $lead->name }}</td>
                            <td class="py-2 pr-4">{{ $lead->phone }}</td>
                            <td class="py-2 pr-4">{{ $lead->city }}</td>
                            <td class="py-2 pr-4">{{ $roles[$lead->role] ?? $lead->role }}</td>
                            <td class="py-2 pr-4">
                                <select wire:change="setStatus({{ $lead->id }}, $event.target.value)" class="border rounded px-2 py-1 text-xs" aria-label="Status for {{ $lead->name }}">
                                    @foreach ($statuses as $status)
                                        <option value="{{ $status }}" @selected($lead->status === $status)>{{ str_replace('_', ' ', $status) }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="py-2 pr-4">{{ $lead->acquisition['utm_source'] ?? $lead->source }}</td>
                            <td class="py-2 pr-4 whitespace-nowrap">{{ $lead->created_at?->format('j M Y, g:i A') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-6 text-center text-gray-500">No leads match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $leads->links() }}</div>
    </x-ui.card>
</div>
