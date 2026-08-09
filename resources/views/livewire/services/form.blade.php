<div class="max-w-2xl">
    <a href="{{ route('admin.services.index') }}" class="text-sm text-blue-600 hover:underline">&larr; Back to Services</a>

    <h1 class="text-2xl font-bold mt-2 mb-1">{{ $serviceId ? 'Edit Service' : 'New Service' }}</h1>

    @if ($flashMessage)
        <div class="bg-green-50 text-green-700 rounded p-3 mb-4 text-sm">{{ $flashMessage }}</div>
    @endif

    <div class="bg-white rounded-lg shadow-sm p-6 space-y-4">
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium mb-1">Category</label>
                <select wire:model.live="categoryId" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="">Select category</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
                @error('categoryId') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Subcategory (optional)</label>
                <select wire:model="subcategoryId" class="w-full border rounded px-3 py-2 text-sm" @if(!$categoryId) disabled @endif>
                    <option value="">None</option>
                    @foreach ($this->subcategories as $sub)
                        <option value="{{ $sub->id }}">{{ $sub->name }}</option>
                    @endforeach
                </select>
                @if (!$categoryId)
                    <p class="text-xs text-gray-400 mt-1">Pick a category first.</p>
                @endif
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium mb-1">Name</label>
            <input type="text" wire:model="name" placeholder="e.g. Split AC Gas Refill" class="w-full border rounded px-3 py-2 text-sm">
            @error('name') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium mb-1">Description</label>
            {{-- wire:ignore on the whole block: Trix manages this DOM itself (cursor
                 position, toolbar state), and Livewire's morph-on-update (triggered by
                 the Category select's wire:model.live above) would otherwise fight it.
                 The hidden input's wire:model binding still works fine under wire:ignore
                 — that only stops DOM morphing, not the already-attached Livewire
                 input-event listener. Trix keeps the hidden input's value in sync and
                 fires a real 'input' event on every change, which is what wire:model
                 listens for. --}}
            <div wire:ignore>
                <input id="description-trix-input" type="hidden" wire:model="description" value="{{ $description }}">
                <trix-editor input="description-trix-input" class="w-full border rounded px-3 py-2 text-sm bg-white"></trix-editor>
            </div>
        </div>

        <div class="grid grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium mb-1">Base price (₹)</label>
                <input type="number" step="0.01" wire:model="basePrice" class="w-full border rounded px-3 py-2 text-sm">
                @error('basePrice') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Discount price (₹)</label>
                <input type="number" step="0.01" wire:model="discountPrice" class="w-full border rounded px-3 py-2 text-sm">
                <p class="text-xs text-gray-400 mt-1">Optional, must be lower than base price.</p>
                @error('discountPrice') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Price type</label>
                <select wire:model="priceType" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="fixed">Fixed</option>
                    <option value="hourly">Hourly</option>
                    <option value="quote_on_inspection">Quote on Inspection</option>
                </select>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium mb-1">Duration estimate (minutes)</label>
                <input type="number" step="1" wire:model="durationEstimateMins" class="w-full border rounded px-3 py-2 text-sm">
                @error('durationEstimateMins') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Cover image URL</label>
                <input type="text" wire:model="coverImage" placeholder="https://…" class="w-full border rounded px-3 py-2 text-sm">
            </div>
        </div>

        <label class="inline-flex items-center gap-2 text-sm">
            <input type="checkbox" wire:model="isActive" class="rounded"> Active
        </label>

        <div class="pt-2">
            <button wire:click="save" class="bg-slate-900 text-white px-6 py-2 rounded text-sm font-medium hover:bg-slate-800">
                Save Service
            </button>
        </div>
    </div>
</div>
