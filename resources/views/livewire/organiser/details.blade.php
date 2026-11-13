<div class="space-y-5">
    <h1 class="text-2xl font-bold tracking-tight">Organiser details</h1>
    <p class="text-sm text-slate-600">Ticket buyers see your name, logo and these few lines on every event page.</p>

    <form wire:submit="save" class="space-y-4 rounded-2xl border border-slate-200 bg-white p-4 sm:p-6">
        <div>
            <label for="o-name" class="block text-sm font-medium text-slate-900">Organiser or company name</label>
            <input id="o-name" type="text" wire:model="name" class="mt-1.5 block min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-base">
            @error('name') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="o-about" class="block text-sm font-medium text-slate-900">About you</label>
            <textarea id="o-about" wire:model="about" rows="4" placeholder="e.g. We have run DJ nights and New Year parties in Nellore since 2019." class="mt-1.5 block w-full rounded-lg border border-slate-300 px-3 py-2.5 text-base"></textarea>
            @error('about') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="o-phone" class="block text-sm font-medium text-slate-900">Phone for buyers</label>
                <input id="o-phone" type="tel" wire:model="contactPhone" class="mt-1.5 block min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-base">
                @error('contactPhone') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="o-gst" class="block text-sm font-medium text-slate-900">GST number (if you have one)</label>
                <input id="o-gst" type="text" wire:model="gstNumber" class="mt-1.5 block min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-base uppercase">
                @error('gstNumber') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
        </div>
        <div>
            <label for="o-address" class="block text-sm font-medium text-slate-900">Office address</label>
            <input id="o-address" type="text" wire:model="addressLine" class="mt-1.5 block min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-base">
            @error('addressLine') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>
        <div class="flex items-center gap-4">
            <div class="h-16 w-16 shrink-0 overflow-hidden rounded-xl bg-slate-100">
                @if ($logoUrl) <img src="{{ $logoUrl }}" alt="Logo" class="h-full w-full object-cover"> @endif
            </div>
            <div class="min-w-0 flex-1">
                <label for="o-logo" class="block text-sm font-medium text-slate-900">{{ $logoUrl ? 'Change your logo' : 'Logo (optional)' }}</label>
                <input id="o-logo" type="file" accept="image/jpeg,image/png,image/webp" wire:model="logo" class="mt-1.5 block w-full text-sm">
                <x-ui.image-size use="organiser_logo" />
                @error('logo') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
        </div>
        <div class="flex items-center gap-3">
            <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save,logo">Save</x-ui.button>
            @if ($notice) <span class="text-sm text-emerald-700" role="status">{{ $notice }}</span> @endif
        </div>
    </form>
</div>
