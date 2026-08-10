@props(['value'])

{{-- Yes/No pill used by the read-only detail modals, matching the reference
     panel's green/red badges rather than a bare "1"/"0". --}}
<span @class([
    'inline-block px-2 py-0.5 rounded text-xs font-medium',
    'bg-green-100 text-green-700' => $value,
    'bg-red-100 text-red-700' => ! $value,
])>{{ $value ? 'Yes' : 'No' }}</span>
