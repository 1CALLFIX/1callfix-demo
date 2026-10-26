{{-- Type, city, franchise and zone filters shared by the People and Businesses lists (FiltersPartners). Every
     option comes from the user's own area. --}}
<div class="mb-4 flex flex-wrap items-center gap-2 text-sm">
    <select wire:model.live="typeFilter" aria-label="Partner type" class="border rounded px-2 py-2">
        <option value="">{{ $typeLabel }}</option>
        @foreach ($typeOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
    </select>
    @if (count($cityOptions) > 1)
        <select wire:model.live="cityFilter" aria-label="City" class="border rounded px-2 py-2">
            <option value="">All cities</option>
            @foreach ($cityOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
        </select>
    @endif
    @if (count($franchiseOptions) > 1)
        <select wire:model.live="franchiseFilter" aria-label="Franchise" class="border rounded px-2 py-2">
            <option value="">All franchises</option>
            @foreach ($franchiseOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
        </select>
    @endif
    @if (count($zoneOptions) > 1)
        <select wire:model.live="zoneFilter" aria-label="Zone" class="border rounded px-2 py-2">
            <option value="">All zones</option>
            @foreach ($zoneOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
        </select>
    @endif
</div>
