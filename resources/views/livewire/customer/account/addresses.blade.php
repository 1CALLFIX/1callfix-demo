{{-- Phase E6 — saved addresses. Same write/derivation/delete-guard rules as
     API\AddressController. --}}
<div class="mx-auto max-w-2xl px-4 py-6 sm:px-6 lg:px-8">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold tracking-tight">Saved addresses</h1>
        <a href="{{ route('customer.account') }}" wire:navigate class="text-sm text-slate-500 hover:text-slate-900">Account</a>
    </div>

    @if ($notice)
        <div role="status" class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ $notice }}</div>
    @endif
    @if ($error)
        <div role="alert" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $error }}</div>
    @endif

    <div class="mt-4 space-y-3">
        @forelse ($addresses as $address)
            <div class="rounded-xl border border-slate-200 p-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-medium text-slate-900">
                            {{ $address->label }}
                            @if ($address->is_default)<span class="ml-1.5 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-600">Default</span>@endif
                        </p>
                        <p class="text-sm text-slate-600">{{ $address->address_line }}@if ($address->city), {{ $address->city }}@endif</p>
                        @if ($address->landmark)<p class="text-xs text-slate-400">Near {{ $address->landmark }}</p>@endif
                        @unless ($address->zone_id)
                            <p class="mt-1 text-xs text-rose-600">No service area set — bookings can't use this address.</p>
                            <button wire:click="assignCurrentArea({{ $address->id }})"
                                    class="mt-1 rounded-md border border-blue-200 bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700 hover:bg-blue-100">
                                Use my selected area
                            </button>
                        @endunless
                    </div>
                    <div class="flex shrink-0 gap-1">
                        <button wire:click="edit({{ $address->id }})" class="rounded-md px-2.5 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-100">Edit</button>
                        <button wire:click="delete({{ $address->id }})" class="rounded-md px-2.5 py-1.5 text-xs font-medium text-rose-600 hover:bg-rose-50">Delete</button>
                    </div>
                </div>
            </div>
        @empty
            <p class="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-600">No saved addresses yet.</p>
        @endforelse
    </div>

    @if ($showForm)
        <div class="mt-4 rounded-xl border border-slate-200 p-4">
            <p class="text-sm font-semibold">{{ $editingId ? 'Edit address' : 'Add address' }}</p>

            @unless ($editingId)
                <button type="button" data-locate-address
                        class="mt-3 inline-flex min-h-11 items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-800 hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                    <x-icon name="map" class="h-4 w-4 text-slate-500" />
                    <span data-locate-address-label>Use my current location</span>
                </button>
                @if ($locatedZoneName !== '')
                    <p class="mt-1.5 text-xs text-emerald-700">
                        Pinned to your location — service area <span class="font-medium">{{ $locatedZoneName }}</span>.
                    </p>
                @endif
            @endunless

            <div class="mt-3 grid gap-2 sm:grid-cols-2">
                <div>
                    <input wire:model="form.label" placeholder="Label" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    @error('form.label') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <input wire:model="form.city" placeholder="City" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <div class="sm:col-span-2">
                    <input wire:model="form.address_line" placeholder="Flat / street / area" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    @error('form.address_line') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <input wire:model="form.landmark" placeholder="Landmark (optional)" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <input wire:model="form.pincode" placeholder="PIN code (optional)" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            </div>
            <label class="mt-2 flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" wire:model="form.is_default" class="h-4 w-4 accent-blue-600"> Make this my default address
            </label>
            <div class="mt-3 flex gap-2">
                <button wire:click="save" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">Save</button>
                <button wire:click="$set('showForm', false)" class="rounded-lg px-4 py-2 text-sm text-slate-600 hover:bg-slate-100">Cancel</button>
            </div>
        </div>
    @else
        <button wire:click="startAdd"
                class="mt-4 inline-flex items-center gap-1.5 rounded-lg border border-dashed border-slate-300 px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
            <x-icon name="plus" class="h-4 w-4" /> Add an address
        </button>
    @endif

    {{-- "Use my current location" wiring — shared helper, see resources/js/geolocation.js --}}
    @script
    <script>window.cfWireLocateButton($wire);</script>
    @endscript
</div>
