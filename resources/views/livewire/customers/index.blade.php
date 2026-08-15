<div>
    <h1 class="text-2xl font-bold mb-4">Customers</h1>

    <div class="flex flex-wrap gap-3 mb-4">
        <input type="text" wire:model.live.debounce.400ms="search" placeholder="Search name, phone, or email..." class="border rounded px-3 py-2 text-sm w-72">
        <select wire:model.live="statusFilter" class="border rounded px-3 py-2 text-sm">
            <option value="">All statuses</option>
            <option value="active">Active</option>
            <option value="suspended">Suspended</option>
            <option value="pending_verification">Pending verification</option>
        </select>
    </div>

    <div class="bg-white rounded-lg shadow-sm overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-gray-500">
                <tr>
                    <th class="px-4 py-2">Name</th>
                    <th class="px-4 py-2">Phone</th>
                    <th class="px-4 py-2">Franchise</th>
                    <th class="px-4 py-2">Bookings</th>
                    <th class="px-4 py-2">Wallet</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2">Joined</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($customers as $customer)
                    <tr class="border-t hover:bg-gray-50">
                        <td class="px-4 py-2">{{ $customer->name }}</td>
                        <td class="px-4 py-2">{{ $customer->phone }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $customer->franchise->name ?? '—' }}</td>
                        <td class="px-4 py-2">{{ $customer->bookings_count }}</td>
                        <td class="px-4 py-2 font-mono">{{ $currencySymbol }}{{ number_format($customer->wallet_balance, 2) }}</td>
                        <td class="px-4 py-2">
                            <span @class([
                                'px-2 py-0.5 rounded text-xs',
                                'bg-green-100 text-green-700' => $customer->status === 'active',
                                'bg-red-100 text-red-700' => $customer->status === 'suspended',
                                'bg-amber-100 text-amber-700' => $customer->status === 'pending_verification',
                            ])>{{ str_replace('_', ' ', $customer->status) }}</span>
                        </td>
                        <td class="px-4 py-2 text-gray-500">{{ $customer->created_at->diffForHumans() }}</td>
                        <td class="px-4 py-2">
                            <a href="{{ route('admin.customers.show', $customer->id) }}" class="text-blue-600 hover:underline">View</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-6 text-center text-gray-400">No customers yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $customers->links() }}</div>
</div>
