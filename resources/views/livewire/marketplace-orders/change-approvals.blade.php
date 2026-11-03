@php
    $photo = fn (?string $p) => $p ? (str_contains($p, '://') ? $p : \Illuminate\Support\Facades\Storage::disk('public')->url($p)) : null;
    $show = function (string $field, $value) use ($photo, $categories) {
        if ($field === 'marketplace_category_id') {
            return e($value ? ($categories[$value] ?? '#'.$value) : '—');
        }
        if ($value === null || $value === '' || $value === []) {
            return '<span class="text-slate-400">—</span>';
        }

        return nl2br(e((string) $value));
    };
@endphp
<div class="space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Changes to approve</h1>
            <p class="text-sm text-slate-600">Sellers' changes to live items and shops. Until you approve, customers, riders and invoices keep the old details. Price, stock and on/off never come here; they change at once.</p>
        </div>
        <div class="flex gap-1 rounded-lg bg-slate-100 p-1 text-sm">
            <button type="button" wire:click="$set('tab', 'waiting')" @class(['rounded-md px-3 py-1.5 font-medium', 'bg-white shadow-sm' => $tab === 'waiting'])>Waiting ({{ $waitingCount }})</button>
            <button type="button" wire:click="$set('tab', 'decided')" @class(['rounded-md px-3 py-1.5 font-medium', 'bg-white shadow-sm' => $tab === 'decided'])>Decided</button>
        </div>
    </div>

    @if ($flashMessage)
        <div role="status" @class(['rounded-lg px-4 py-2.5 text-sm', 'bg-emerald-50 text-emerald-800' => $flashType === 'success', 'bg-red-50 text-red-800' => $flashType !== 'success'])>{{ $flashMessage }}</div>
    @endif

    @forelse ($changes as $c)
        @php
            $isStore = $c->changeable instanceof \App\Models\Store;
            $fields = collect(array_keys($c->new_values))->reject(fn ($f) => $f === 'lng')->values();
        @endphp
        <x-ui.card wire:key="change-{{ $c->id }}">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $isStore ? 'Shop' : 'Item' }} · {{ $c->store?->name }}</p>
                    <h2 class="font-semibold">{{ $isStore ? $c->store?->name : ($c->changeable?->name ?? 'Removed item') }}</h2>
                    <p class="text-xs text-slate-500">Asked by {{ $c->requester?->name ?? '—' }} · {{ $c->created_at?->diffForHumans() }}</p>
                </div>
                @if ($c->status !== \App\Models\PendingChange::PENDING)
                    <span @class(['rounded-full px-2.5 py-0.5 text-xs font-semibold', 'bg-emerald-50 text-emerald-800' => $c->status === 'approved', 'bg-red-50 text-red-800' => $c->status === 'rejected'])>
                        {{ ucfirst($c->status) }} by {{ $c->reviewer?->name ?? '—' }} · {{ $c->reviewed_at?->diffForHumans() }}
                    </span>
                @endif
            </div>

            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-xs text-slate-500"><th class="w-28 py-1 pr-3 font-medium"></th><th class="py-1 pr-3 font-medium">Now (live)</th><th class="py-1 font-medium">New (asked for)</th></tr></thead>
                    <tbody class="divide-y divide-slate-100 align-top">
                        @foreach ($fields as $f)
                            <tr>
                                <td class="py-2 pr-3 font-medium text-slate-700">{{ \App\Models\PendingChange::LABELS[$f] ?? $f }}</td>
                                @foreach (['old_values', 'new_values'] as $side)
                                    <td class="py-2 pr-3">
                                        @if ($f === 'images')
                                            <div class="flex flex-wrap gap-1.5">
                                                @forelse ($c->{$side}['images'] ?? [] as $img)
                                                    <img src="{{ $photo($img) }}" alt="" class="h-16 w-16 rounded-lg border object-cover">
                                                @empty
                                                    <span class="text-slate-400">No photos</span>
                                                @endforelse
                                            </div>
                                        @elseif ($f === 'lat')
                                            @php $lat = $c->{$side}['lat'] ?? null; $lng = $c->{$side}['lng'] ?? null; @endphp
                                            @if ($lat !== null && $lng !== null)
                                                <a href="https://www.google.com/maps?q={{ $lat }},{{ $lng }}" target="_blank" rel="noopener" class="text-blue-700 underline">{{ number_format((float) $lat, 5) }}, {{ number_format((float) $lng, 5) }}</a>
                                            @else
                                                <span class="text-slate-400">—</span>
                                            @endif
                                        @else
                                            {!! $show($f, $c->{$side}[$f] ?? null) !!}
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($c->status === \App\Models\PendingChange::PENDING)
                <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
                    <x-ui.button type="button" wire:click="approve({{ $c->id }})" wire:loading.attr="disabled">Approve</x-ui.button>
                    <input type="text" wire:model="notes.{{ $c->id }}" maxlength="500" placeholder="Reason, if you reject (the seller sees it)" aria-label="Reason for rejecting" class="min-h-10 min-w-0 flex-1 rounded-lg border border-slate-300 px-3 text-sm">
                    <x-ui.button type="button" variant="secondary" wire:click="reject({{ $c->id }})" wire:loading.attr="disabled">Reject</x-ui.button>
                </div>
            @elseif ($c->review_note)
                <p class="mt-2 text-sm text-slate-600">Reason: {{ $c->review_note }}</p>
            @endif
        </x-ui.card>
    @empty
        <x-ui.card><p class="text-sm text-slate-600">{{ $tab === 'waiting' ? 'Nothing waiting. Sellers\' changes to names, photos, addresses and tax details show here.' : 'No decided changes yet.' }}</p></x-ui.card>
    @endforelse

    {{ $changes->links() }}
</div>
