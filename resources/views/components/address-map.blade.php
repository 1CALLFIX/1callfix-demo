@props([
    'componentId',
    'latModel' => 'newAddressLat',
    'lngModel' => 'newAddressLng',
    'centerLat' => null,
    'centerLng' => null,
    'height' => '260px',
])

{{-- Single-marker click-to-place picker, used by the New Booking modal's
     "add new address" form. Same wire:ignore / data-attribute wiring as
     components/zone-map.blade.php (see public/js/booking-address-map.js),
     just one marker instead of a boundary polygon. --}}
<div wire:ignore
     data-address-map
     data-livewire-id="{{ $componentId }}"
     data-lat-model="{{ $latModel }}"
     data-lng-model="{{ $lngModel }}"
     data-center-lat="{{ $centerLat }}"
     data-center-lng="{{ $centerLng }}">

    <div class="flex items-center gap-2 mb-2">
        <span data-role="status" class="text-xs text-gray-400">Click the map to place a marker.</span>
    </div>

    <div data-role="canvas"
         style="height: {{ $height }}; width: 100%; border-radius: 0.5rem; border: 1px solid #e5e7eb;"></div>
</div>
