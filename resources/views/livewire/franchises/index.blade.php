<div>
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold">Franchises</h1>
        <a href="{{ route('admin.franchises.create') }}" class="bg-slate-900 text-white px-4 py-2 rounded text-sm">
            + New Franchise
        </a>
    </div>

    <div class="bg-white rounded-lg shadow-sm overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-gray-500">
                <tr>
                    <th class="px-4 py-2">Name</th>
                    <th class="px-4 py-2">Code</th>
                    <th class="px-4 py-2">City</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2">Commission</th>
                    <th class="px-4 py-2">Zones</th>
                    <th class="px-4 py-2">Providers</th>
                    <th class="px-4 py-2">Bookings</th>
                    <th class="px-4 py-2">Modules</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($franchises as $franchise)
                    <tr class="border-t hover:bg-gray-50">
                        <td class="px-4 py-2 font-medium">{{ $franchise->display_name }}</td>
                        <td class="px-4 py-2 font-mono text-xs">{{ $franchise->code }}</td>
                        <td class="px-4 py-2">{{ $franchise->city }}</td>
                        <td class="px-4 py-2">
                            <span @class([
                                'px-2 py-1 rounded text-xs font-medium',
                                'bg-green-100 text-green-700' => $franchise->status === 'active',
                                'bg-gray-100 text-gray-700' => $franchise->status === 'pending_setup',
                                'bg-red-100 text-red-700' => $franchise->status === 'inactive',
                            ])>{{ str_replace('_', ' ', $franchise->status) }}</span>
                        </td>
                        <td class="px-4 py-2">{{ $franchise->platform_fee_percent }}%</td>
                        <td class="px-4 py-2">{{ $franchise->zones_count }}</td>
                        <td class="px-4 py-2">{{ $franchise->providers_count }}</td>
                        <td class="px-4 py-2">{{ $franchise->bookings_count }}</td>
                        <td class="px-4 py-2">
                            @if ($franchise->modules)
                                <div class="flex gap-1 flex-wrap">
                                    @foreach (['service','food','parcel','taxi','grocery','pharmacy','commerce','bookings'] as $mod)
                                        @if ($franchise->modules->$mod)
                                            <span class="bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded text-xs">{{ $mod }}</span>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                        </td>
                        <td class="px-4 py-2">
                            <a href="{{ route('admin.franchises.edit', $franchise->id) }}" class="text-blue-600 hover:underline">Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="px-4 py-6 text-center text-gray-400">No franchises yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
