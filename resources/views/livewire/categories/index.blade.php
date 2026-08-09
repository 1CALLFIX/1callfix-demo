<div>
    <div class="flex items-center justify-between mb-4">
        <div>
            <a href="{{ route('admin.services.index') }}" class="text-sm text-blue-600 hover:underline">&larr; Back to Services</a>
            <h1 class="text-2xl font-bold mt-1">Categories</h1>
        </div>
        <a href="{{ route('admin.categories.create') }}" class="bg-slate-900 text-white px-4 py-2 rounded text-sm">
            + New Category
        </a>
    </div>

    <div class="space-y-3">
        @forelse ($categories as $category)
            <div class="bg-white rounded-lg shadow-sm overflow-hidden">
                <div class="flex items-center justify-between px-4 py-3 border-b bg-gray-50">
                    <div class="flex items-center gap-2">
                        @if ($category->icon) <span class="text-gray-400">{{ $category->icon }}</span> @endif
                        <span class="font-semibold">{{ $category->name }}</span>
                        <span @class([
                            'px-2 py-0.5 rounded text-xs',
                            'bg-green-100 text-green-700' => $category->is_active,
                            'bg-gray-100 text-gray-500' => ! $category->is_active,
                        ])>{{ $category->is_active ? 'Active' : 'Inactive' }}</span>
                        <span class="text-xs text-gray-400">{{ $category->services_count }} direct services</span>
                    </div>
                    <div class="flex gap-3 text-sm">
                        <a href="{{ route('admin.subcategories.create', ['categoryId' => $category->id]) }}" class="text-indigo-600 hover:underline">+ Subcategory</a>
                        <a href="{{ route('admin.categories.edit', $category->id) }}" class="text-blue-600 hover:underline">Edit</a>
                    </div>
                </div>

                @if ($category->subcategories->isNotEmpty())
                    <div class="divide-y">
                        @foreach ($category->subcategories as $sub)
                            <div class="flex items-center justify-between px-4 py-2 pl-8 text-sm">
                                <div class="flex items-center gap-2">
                                    <span class="text-gray-400">&mdash;</span>
                                    <span>{{ $sub->name }}</span>
                                    <span @class([
                                        'px-2 py-0.5 rounded text-xs',
                                        'bg-green-100 text-green-700' => $sub->is_active,
                                        'bg-gray-100 text-gray-500' => ! $sub->is_active,
                                    ])>{{ $sub->is_active ? 'Active' : 'Inactive' }}</span>
                                    <span class="text-xs text-gray-400">{{ $sub->services_count }} services</span>
                                </div>
                                <div class="flex gap-3">
                                    <a href="{{ route('admin.services.index', ['subcategoryId' => $sub->id]) }}" class="text-emerald-600 hover:underline">View Services</a>
                                    <a href="{{ route('admin.subcategories.edit', $sub->id) }}" class="text-blue-600 hover:underline">Edit</a>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="px-4 py-2 pl-8 text-xs text-gray-400">No subcategories yet — services can still attach directly to this category.</div>
                @endif
            </div>
        @empty
            <div class="bg-white rounded-lg shadow-sm p-6 text-center text-gray-400">
                No categories yet. Create your first one to start adding services.
            </div>
        @endforelse
    </div>
</div>
