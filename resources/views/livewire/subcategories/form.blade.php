<div class="max-w-2xl">
    <a href="{{ route('admin.categories.index') }}" class="text-sm text-blue-600 hover:underline">&larr; Back to Categories</a>

    <h1 class="text-2xl font-bold mt-2 mb-4">{{ $subcategoryId ? 'Edit Subcategory' : 'New Subcategory' }}</h1>

    @if ($flashMessage)
        <div class="bg-green-50 text-green-700 rounded p-3 mb-4 text-sm">{{ $flashMessage }}</div>
    @endif

    <div class="bg-white rounded-lg shadow-sm p-6 space-y-4">
        <div>
            <label class="block text-sm font-medium mb-1">Parent Category</label>
            <select wire:model="categoryId" class="w-full border rounded px-3 py-2 text-sm">
                <option value="">Select category</option>
                @foreach ($categories as $cat)
                    <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                @endforeach
            </select>
            @error('categoryId') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium mb-1">Subcategory Name</label>
            <input type="text" wire:model="name" placeholder="e.g. Fan Installation (under Electrical)" class="w-full border rounded px-3 py-2 text-sm">
            @error('name') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium mb-1">Icon (optional)</label>
                <input type="text" wire:model="icon" class="w-full border rounded px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Sort Order</label>
                <input type="number" wire:model="sortOrder" class="w-full border rounded px-3 py-2 text-sm">
            </div>
        </div>

        <div class="flex items-end gap-3">
            <div class="flex-1">
                <label class="block text-sm font-medium mb-1">Image URL (optional)</label>
                <input type="text" wire:model="image" placeholder="https://…" class="w-full border rounded px-3 py-2 text-sm">
                @error('image') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            @if ($image)
                <img src="{{ $image }}" alt="" class="w-12 h-12 rounded object-cover border">
            @endif
        </div>

        <div>
            <label class="block text-sm font-medium mb-1">Description (optional)</label>
            <textarea wire:model="description" rows="2" class="w-full border rounded px-3 py-2 text-sm"></textarea>
        </div>

        <label class="inline-flex items-center gap-2 text-sm">
            <input type="checkbox" wire:model="isActive" class="rounded"> Active
        </label>

        <button wire:click="save" class="bg-slate-900 text-white px-6 py-2 rounded text-sm font-medium hover:bg-slate-800">
            Save Subcategory
        </button>
    </div>
</div>
