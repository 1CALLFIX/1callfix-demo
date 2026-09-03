@props(['name' => null])

@php
    // $name, when passed, drives the red invalid border — same visual state
    // the auth forms hand-rolled before this component. The validation
    // message itself still renders in each form, untouched. ($errors is
    // null-guarded so the component also renders outside a request, e.g.
    // Blade::render in a test.)
    $invalid = $name && isset($errors) && $errors->has($name);
@endphp

{{--
    Shared password field with a show/hide toggle. One component, one JS
    behaviour (resources/js/password-toggle.js, bundled in app.js) — every
    auth form reuses this instead of repeating the input markup plus its own
    toggle. Forwards the attribute bag, so callers pass id / wire:model /
    autocomplete / autofocus / required exactly as they did on the raw
    input. The field is always a password server-side; the toggle flips it
    client-side.
--}}
<div class="relative mt-1.5" data-password-field>
    <input
        {{ $attributes->merge([
            'type' => 'password',
            'class' => 'block min-h-11 w-full rounded-lg border px-3 py-2.5 pr-11 text-base shadow-sm focus:outline focus:outline-2 focus:outline-offset-0 focus:outline-blue-600 '
                . ($invalid ? 'border-red-400' : 'border-slate-300'),
        ]) }}
    >

    <button type="button" data-password-toggle tabindex="-1"
            aria-label="Show password" aria-pressed="false"
            class="absolute inset-y-0 right-0 flex items-center rounded-r-lg px-3 text-slate-400 transition hover:text-slate-700 focus:outline focus:outline-2 focus:outline-blue-600">
        {{-- eye: password currently hidden --}}
        <svg data-icon-show xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
             stroke-width="1.6" stroke="currentColor" class="h-5 w-5" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round"
                  d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.964-7.178Z" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
        </svg>
        {{-- eye-slash: password currently visible --}}
        <svg data-icon-hide xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
             stroke-width="1.6" stroke="currentColor" class="hidden h-5 w-5" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round"
                  d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" />
        </svg>
    </button>
</div>
