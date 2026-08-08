<div class="max-w-2xl">
    <a href="{{ route('admin.franchises.index') }}" class="text-sm text-blue-600 hover:underline">&larr; Back to Franchises</a>

    <h1 class="text-2xl font-bold mt-2 mb-1">{{ $franchiseId ? 'Edit Franchise' : 'New Franchise' }}</h1>
    @if ($code)
        <div class="text-sm text-gray-500 mb-4">Code: <span class="font-mono">{{ $code }}</span> (auto-generated from name, editable in the database if you need something specific)</div>
    @endif

    @if ($flashMessage)
        <div class="bg-green-50 text-green-700 rounded p-3 mb-4 text-sm">{{ $flashMessage }}</div>
    @endif

    <div class="bg-white rounded-lg shadow-sm p-6 space-y-4">
        <div>
            <label class="block text-sm font-medium mb-1">Name</label>
            <input type="text" wire:model="name" placeholder="e.g. Nellore" class="w-full border rounded px-3 py-2 text-sm">
            @error('name') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium mb-1">City</label>
                <input type="text" wire:model="city" class="w-full border rounded px-3 py-2 text-sm">
                @error('city') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">State</label>
                <input type="text" wire:model="state" class="w-full border rounded px-3 py-2 text-sm">
            </div>
        </div>

        <div class="grid grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium mb-1">Commission Model</label>
                <select wire:model="commissionModel" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="revenue_share">Revenue Share</option>
                    <option value="flat_fee">Flat Fee</option>
                    <option value="subscription_only">Subscription Only</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Franchise Commission %</label>
                <input type="number" step="0.01" wire:model="commissionValue" class="w-full border rounded px-3 py-2 text-sm">
                <p class="text-xs text-gray-400 mt-1">0 if this is your own operated franchise</p>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Platform Fee %</label>
                <input type="number" step="0.01" wire:model="platformFeePercent" class="w-full border rounded px-3 py-2 text-sm">
                @error('platformFeePercent') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium mb-1">Status</label>
            <select wire:model="status" class="w-full border rounded px-3 py-2 text-sm">
                <option value="pending_setup">Pending Setup (not visible to customers)</option>
                <option value="active">Active (live)</option>
                <option value="inactive">Inactive</option>
            </select>
        </div>

        <div class="border-t pt-4">
            <div class="text-sm font-medium mb-2">Modules</div>
            <p class="text-xs text-gray-400 mb-3">Services is always on. Everything else is reserved for future verticals — toggling one on here does not activate any functionality yet, it just marks intent for this franchise.</p>
            <div class="grid grid-cols-2 gap-2">
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" checked disabled class="rounded"> Services (always on)
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="modFood" class="rounded"> Food Delivery
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="modParcel" class="rounded"> Parcel Delivery
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="modTaxi" class="rounded"> Taxi / Ride-hailing
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="modGrocery" class="rounded"> Grocery
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="modPharmacy" class="rounded"> Pharmacy
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="modCommerce" class="rounded"> Commerce/Shopping
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="modBookings" class="rounded"> Hotel/Property Bookings
                </label>
            </div>
        </div>

        <button wire:click="save" class="bg-slate-900 text-white px-6 py-2 rounded text-sm font-medium hover:bg-slate-800">
            Save Franchise
        </button>
    </div>
</div>
