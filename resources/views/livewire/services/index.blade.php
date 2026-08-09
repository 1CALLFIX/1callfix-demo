<div>
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold">Services</h1>
        <div class="flex gap-2">
            <a href="{{ route('admin.categories.index') }}" class="px-4 py-2 border border-gray-300 rounded text-sm hover:bg-gray-50">
                Manage Categories
            </a>
            <a href="{{ route('admin.services.create') }}" class="bg-slate-900 text-white px-4 py-2 rounded text-sm">
                + New Service
            </a>
        </div>
    </div>

    <select wire:model.live="categoryId" class="border rounded px-3 py-2 text-sm mb-4">
        <option value="">All categories</option>
        @foreach ($categories as $cat)
            <option value="{{ $cat->id }}">{{ $cat->name }}</option>
        @endforeach
    </select>

    <div class="bg-white rounded-lg shadow-sm overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-gray-500">
                <tr>
                    <th class="px-4 py-2">Service</th>
                    <th class="px-4 py-2">Category</th>
                    <th class="px-4 py-2">Price</th>
                    <th class="px-4 py-2">Price Type</th>
                    <th class="px-4 py-2">Duration</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($services as $service)
                    <tr class="border-t hover:bg-gray-50">
                        <td class="px-4 py-2 font-medium">{{ $service->name }}</td>
                        <td class="px-4 py-2 text-gray-500">
                            {{ $service->category?->name ?? '—' }}
                            @if ($service->subcategory)
                                <span class="text-gray-400">/ {{ $service->subcategory->name }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2">
                            @if ($service->discount_price)
                                <span class="line-through text-gray-400">₹{{ number_format($service->base_price, 2) }}</span>
                                <span class="text-green-700 font-medium">₹{{ number_format($service->discount_price, 2) }}</span>
                            @else
                                ₹{{ number_format($service->base_price, 2) }}
                            @endif
                        </td>
                        <td class="px-4 py-2 text-gray-500">{{ str_replace('_', ' ', $service->price_type) }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $service->duration_estimate_mins }} min</td>
                        <td class="px-4 py-2">
                            <span @class([
                                'px-2 py-1 rounded text-xs font-medium',
                                'bg-green-100 text-green-700' => $service->is_active,
                                'bg-gray-100 text-gray-700' => ! $service->is_active,
                            ])>{{ $service->is_active ? 'Active' : 'Inactive' }}</span>
                        </td>
                        <td class="px-4 py-2">
                            <a href="{{ route('admin.services.edit', $service->id) }}" class="text-blue-600 hover:underline">Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-6 text-center text-gray-400">No services yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $services->links() }}
    </div>
</div>
