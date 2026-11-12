<div class="space-y-6">
    <div>
        <h1 class="text-xl font-semibold">Partner documents</h1>
        <p class="text-sm text-gray-500">What each kind of business uploads before approval. "Required" blocks approval until it is approved; "If you have it" is optional. Partners upload these from "Go live" after joining (or on the sign-up page when that is switched on in Homepage &amp; sign-up).</p>
    </div>

    <div class="flex flex-wrap gap-2" role="tablist">
        @foreach ($types as $code => $name)
            <button type="button" role="tab" wire:click="$set('type', '{{ $code }}')" aria-selected="{{ $type === $code ? 'true' : 'false' }}"
                    @class(['rounded-full border px-3 py-1.5 text-sm font-medium', 'border-indigo-600 bg-indigo-600 text-white' => $type === $code, 'border-gray-300 bg-white text-gray-700' => $type !== $code])>{{ $name }}</button>
        @endforeach
    </div>

    @if ($notice) <div role="status" class="rounded bg-green-50 px-3 py-2 text-sm text-green-800">{{ $notice }}</div> @endif

    <x-ui.card>
        <form wire:submit="save" class="space-y-3" data-testid="partner-documents">
            @forelse ($rows as $id => $row)
                <div class="grid items-center gap-3 border-b border-gray-100 pb-3 md:grid-cols-[1fr_auto_auto]">
                    <div>
                        <label class="sr-only" for="pd-{{ $id }}">Document name</label>
                        <input id="pd-{{ $id }}" type="text" wire:model="rows.{{ $id }}.label" class="w-full rounded border px-3 py-2 text-sm">
                        @error('rows.'.$id.'.label') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="rows.{{ $id }}.is_required"> Required</label>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="rows.{{ $id }}.is_active"> On</label>
                </div>
            @empty
                <p class="text-sm text-gray-500">No documents yet for {{ $types[$type] }}.</p>
            @endforelse
            @if ($rows !== [])
                <button type="submit" class="rounded bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Save</button>
            @endif
        </form>
    </x-ui.card>

    <x-ui.card>
        <form wire:submit="add" class="grid items-end gap-3 md:grid-cols-[1fr_auto_auto]">
            <div>
                <label class="mb-1 block text-sm font-medium" for="pd-new">Add a document for {{ $types[$type] }}</label>
                <input id="pd-new" type="text" wire:model="newLabel" placeholder="e.g. Fire safety certificate" class="w-full rounded border px-3 py-2 text-sm">
                @error('newLabel') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="newRequired"> Required</label>
            <button type="submit" class="rounded border border-indigo-600 px-4 py-2 text-sm font-semibold text-indigo-700">Add</button>
        </form>
    </x-ui.card>
</div>
