@props(['tabs', 'active' => '', 'model', 'counts' => null, 'alerts' => []])
{{-- Pill tab row for admin lists (1CF-ADMIN-TABS-001). Sets the Livewire
     property named by `model` on click; `counts` (optional) is key => number;
     `alerts` (optional) is key => number, shown in red as "(3 · 1 overdue)". --}}
<div {{ $attributes->merge(['class' => 'flex flex-wrap gap-2']) }} role="tablist">
    @foreach ($tabs as $key => $label)
        <button type="button" role="tab"
                aria-selected="{{ (string) $active === (string) $key ? 'true' : 'false' }}"
                wire:click="$set('{{ $model }}', '{{ $key }}')"
                class="px-3 py-1.5 rounded text-sm {{ (string) $active === (string) $key ? 'bg-slate-900 text-white' : 'bg-white border hover:bg-gray-50' }}">
            {{ $label }}
            @if ($counts !== null && isset($counts[$key]))
                @php $alert = (int) ($alerts[$key] ?? 0); @endphp
                <span class="opacity-60">({{ $counts[$key] }}{{ $alert > 0 ? ' ·' : ')' }}</span>
                @if ($alert > 0)
                    <span @class(['font-semibold', 'text-red-300' => (string) $active === (string) $key, 'text-red-600' => (string) $active !== (string) $key])>{{ $alert }} overdue</span><span class="opacity-60">)</span>
                @endif
            @endif
        </button>
    @endforeach
</div>
