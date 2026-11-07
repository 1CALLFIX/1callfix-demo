@php
    use App\Support\InfoPages\ContactDetails as C;
    $input = 'w-full border rounded px-3 py-2 text-sm';
@endphp
<div>
    <div class="flex flex-wrap items-start justify-between gap-3 mb-1">
        <h1 class="text-xl font-semibold">Contact page</h1>
        <a href="{{ route('customer.contact') }}" target="_blank" rel="noopener" class="text-sm font-medium text-blue-700 underline">Open the page ↗</a>
    </div>
    <p class="text-sm text-gray-500 mb-4">
        The company's contact details on the public <code>/contact</code> page, and the "Talk to us" button on the Franchise page.
        Super Admin only. A blank field is not shown. The page's words (headings, button labels) are in
        <a class="underline" href="{{ route('admin.site-text') }}">Website text</a> → "Contact page", in English and Telugu.
        The grievance officer comes from Shop Settings. Every change is audit-logged.
    </p>

    @if ($flashMessage)
        <div role="status" class="rounded px-4 py-2 mb-4 text-sm bg-green-50 text-green-700">{{ $flashMessage }}</div>
    @endif

    <form wire:submit="save" class="space-y-6">
        <x-ui.card>
            <label class="flex items-start gap-2 text-sm">
                <input type="checkbox" wire:model="enabled" class="mt-1 rounded border-gray-300">
                <span><span class="font-medium">Contact page is live</span><br><span class="text-xs text-gray-500">Off: /contact shows "page not found" and its links disappear.</span></span>
            </label>
        </x-ui.card>
        <x-ui.card>
            <div class="grid gap-4 sm:grid-cols-2">
                @foreach (C::FIELDS as $field => [$label, $max, $hint])
                    <div @class(['sm:col-span-2' => in_array($field, ['address', 'map_url'], true)])>
                        <label class="block text-sm font-medium mb-1" for="ct-{{ $field }}">{{ $label }}</label>
                        @if ($field === 'address')
                            <textarea id="ct-{{ $field }}" wire:model="v.{{ $field }}" rows="3" maxlength="{{ $max }}" @class([$input, 'border-red-500' => $errors->has('v.'.$field)])></textarea>
                        @else
                            <input id="ct-{{ $field }}" type="text" wire:model="v.{{ $field }}" maxlength="{{ $max }}" @class([$input, 'border-red-500' => $errors->has('v.'.$field)])>
                        @endif
                        <p class="mt-1 text-xs text-gray-500">{{ $hint }}</p>
                        @error('v.'.$field)<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                @endforeach
            </div>
        </x-ui.card>
        <button type="submit" class="rounded bg-blue-600 px-5 py-2 text-sm font-semibold text-white hover:bg-blue-700" wire:loading.attr="disabled">Save</button>
    </form>
</div>
