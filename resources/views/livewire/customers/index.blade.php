<div>
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold">Customers</h1>
        <div class="flex gap-2 text-sm">
            <x-ui.button variant="secondary" size="sm" wire:click="exportCustomersCsv" title="Export the current filtered view as CSV">Export CSV</x-ui.button>
            <x-ui.button variant="secondary" size="sm" wire:click="toggleCustomersPrereg">
                {{ $showCustomersPrereg ? 'Cancel' : 'Bulk Pre-Register' }}
            </x-ui.button>
        </div>
    </div>

    @error('permission') <div class="bg-red-50 text-red-700 rounded p-3 mb-4 text-sm">{{ $message }}</div> @enderror

    @if ($showCustomersPrereg)
        <x-prereg-panel label="Customers"
            file-model="customersPreregFile"
            validate-method="validateCustomersPrereg"
            commit-method="commitCustomersPrereg"
            cancel-method="toggleCustomersPrereg"
            warning="Creates PENDING account shells only — these are NOT active, usable accounts. Each customer still needs to complete a real OTP verification (their normal first login) before they can authenticate or book anything. Columns: name, phone (required), email (optional)."
            :row-errors="$customersPreregErrors"
            :rows="$customersPreregRows"
            :message="$customersPreregMessage"
            :run="$customersPreregRun" />
    @endif

    <div class="flex flex-wrap gap-3 mb-4">
        <input type="text" wire:model.live.debounce.400ms="search" placeholder="Search name, phone, or email..." class="border rounded px-3 py-2 text-sm w-72">
        <select wire:model.live="statusFilter" class="border rounded px-3 py-2 text-sm">
            <option value="">All statuses</option>
            <option value="active">Active</option>
            <option value="suspended">Suspended</option>
            <option value="pending_verification">Pending verification</option>
        </select>
    </div>

    <x-ui.table>
        <x-slot:footer>{{ $customers->links() }}</x-slot:footer>

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
                        <x-ui.status-badge type="customer" :status="$customer->status" />
                    </td>
                    <td class="px-4 py-2 text-gray-500">{{ $customer->created_at->diffForHumans() }}</td>
                    <td class="px-4 py-2">
                        <x-ui.button variant="ghost" :href="route('admin.customers.show', $customer->id)">View</x-ui.button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8"><x-ui.empty-state icon="users" title="No customers yet" /></td></tr>
            @endforelse
        </tbody>
    </x-ui.table>
</div>
