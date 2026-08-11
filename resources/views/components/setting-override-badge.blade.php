@props(['overridden' => false, 'settingKey'])

{{-- Only meaningful once a non-global scope is picked — see Settings\Manage's
     scope picker. "overridden" means a row exists at the EXACT picked scope
     (Setting::existsAt), not just that the resolved value differs from
     global's. --}}
@if ($overridden)
    <span class="inline-flex items-center gap-1 text-[10px] text-amber-700 bg-amber-50 px-1.5 py-0.5 rounded ml-2 align-middle">
        overridden here
        <button type="button" wire:click="clearOverride('{{ $settingKey }}')" class="underline hover:no-underline">clear</button>
    </span>
@else
    <span class="text-[10px] text-gray-400 ml-2 align-middle">inherited</span>
@endif
