{{-- Shared, screen-reader-announced, never colour-only (WCAG 2.1 AA
     1.4.1 / 3.3.1). Expects $error and/or $status in scope. --}}
@if (!empty($error))
    <div role="alert" class="mb-5 flex items-start gap-2 rounded-lg bg-red-50 px-3 py-2.5 text-sm text-red-800">
        <span aria-hidden="true" class="mt-px font-bold">!</span>
        <span>{{ $error }}</span>
    </div>
@endif

@if (!empty($status))
    <div role="status" class="mb-5 flex items-start gap-2 rounded-lg bg-emerald-50 px-3 py-2.5 text-sm text-emerald-800">
        <span aria-hidden="true" class="mt-px font-bold">&check;</span>
        <span>{{ $status }}</span>
    </div>
@endif
