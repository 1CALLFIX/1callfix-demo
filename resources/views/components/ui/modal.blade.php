@props([
    'show' => false,
    'title' => null,
    'onClose' => null,
    'maxWidth' => 'md',
])

{{--
    Phase 21 item TECH-6 (Admin UI design system, first increment). No modal
    exists anywhere in the admin panel today -- every screen so far has used
    inline sections/tabs instead (Badges\Manage's "Assign a badge" panel,
    Flash Sales\Manage's "New flash sale" panel, etc.). This is a real
    primitive with no prior in-repo pattern to copy, so it's deliberately
    kept dependency-free: $show is driven entirely by a plain Livewire
    boolean property and the whole thing is wrapped in a Blade @if, exactly
    like every other conditional block in this codebase (Badges\Manage's
    $section tabs, Subscriptions\Index's flash message) -- no Alpine
    x-show/x-data, no separate JS asset, nothing that isn't already a
    dependency of every other screen. $onClose is the name of a Livewire
    method (e.g. "closeModal") wired onto both the backdrop and the X
    button; pass null to render a modal with no dismiss affordance (the
    caller's own footer buttons are then the only way to close it).
--}}

@php
    $maxWidths = [
        'sm' => 'max-w-sm',
        'md' => 'max-w-md',
        'lg' => 'max-w-lg',
        'xl' => 'max-w-xl',
        '2xl' => 'max-w-2xl',
        // Fourth increment (Zones\Manage): the Edit Zone modal carries a real
        // 2-column form + boundary map side-by-side (x-zone-map) and needs
        // more room than 2xl gives it -- additive, no prior maxWidth call
        // site is affected.
        '3xl' => 'max-w-3xl',
    ];

    $maxWidthClass = $maxWidths[$maxWidth] ?? $maxWidths['md'];
@endphp

@if ($show)
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div
            class="fixed inset-0 bg-black/40"
            @if ($onClose) wire:click="{{ $onClose }}" @endif
        ></div>

        <div {{ $attributes->merge(['class' => "relative bg-white rounded-lg shadow-lg w-full {$maxWidthClass} max-h-[90vh] overflow-y-auto"]) }}>
            @if ($title || $onClose)
                <div class="flex items-center justify-between px-5 py-4 border-b">
                    <h3 class="text-base font-semibold">{{ $title }}</h3>
                    @if ($onClose)
                        <button type="button" wire:click="{{ $onClose }}" class="text-gray-400 hover:text-gray-600 text-xl leading-none" aria-label="Close">&times;</button>
                    @endif
                </div>
            @endif

            <div class="p-5">
                {{ $slot }}
            </div>

            @isset($footer)
                <div class="px-5 py-4 border-t flex justify-end gap-2">
                    {{ $footer }}
                </div>
            @endisset
        </div>
    </div>
@endif
