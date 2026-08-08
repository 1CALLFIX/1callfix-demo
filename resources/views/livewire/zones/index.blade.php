<div>
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold">Zones</h1>
        <a href="{{ route('admin.zones.create') }}" class="bg-slate-900 text-white px-4 py-2 rounded text-sm">
            + New Zone
        </a>
    </div>

    <div class="bg-white rounded-lg shadow-sm overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-gray-500">
                <tr>
                    <th class="px-4 py-2">Zone</th>
                    <th class="px-4 py-2">Code</th>
                    <th class="px-4 py-2">Franchise</th>
                    <th class="px-4 py-2">Dispatch Radius</th>
                    <th class="px-4 py-2">Providers</th>
                    <th class="px-4 py-2">Bookings</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($zones as $zone)
                    <tr class="border-t hover:bg-gray-50">
                        <td class="px-4 py-2 font-medium">{{ $zone->display_name }}</td>
                        <td class="px-4 py-2 font-mono text-xs">{{ $zone->code }}</td>
                        <td class="px-4 py-2">{{ $zone->franchise?->display_name ?? '—' }}</td>
                        <td class="px-4 py-2">{{ $zone->default_dispatch_radius_km }} km</td>
                        <td class="px-4 py-2">{{ $zone->providers_count }}</td>
                        <td class="px-4 py-2">{{ $zone->bookings_count }}</td>
                        <td class="px-4 py-2">
                            <span @class([
                                'px-2 py-1 rounded text-xs font-medium',
                                'bg-green-100 text-green-700' => $zone->is_active,
                                'bg-gray-100 text-gray-700' => ! $zone->is_active,
                            ])>{{ $zone->is_active ? 'Active' : 'Inactive' }}</span>
                        </td>
                        <td class="px-4 py-2">
                            <a href="{{ route('admin.zones.edit', $zone->id) }}" class="text-blue-600 hover:underline">Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-6 text-center text-gray-400">No zones yet. Draw your first one to enable dispatch in that area.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
