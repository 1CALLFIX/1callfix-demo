<div>
    <div class="mb-4">
        <h1 class="text-2xl font-bold">Shop Returns</h1>
        <p class="text-sm text-gray-500">Damaged or wrong items, within the return window. Refunds follow the approval model (Refund Controls → Shop return refunds).</p>
    </div>

    @if ($flashMessage)
        <div @class(['mb-4 rounded px-4 py-2 text-sm', 'bg-green-50 text-green-700' => $flashType === 'success', 'bg-red-50 text-red-700' => $flashType !== 'success'])>{{ $flashMessage }}</div>
    @endif

    <x-ui.filter-tabs class="mb-3" :tabs="['open' => 'Open', 'refunded' => 'Refunded', 'declined' => 'Declined', 'all' => 'All']" :active="$statusFilter" model="statusFilter" />

    <x-ui.table>
        <x-slot:footer>{{ $rows->links() }}</x-slot:footer>
        <thead class="bg-gray-50 text-left text-gray-500">
            <tr>
                <th class="px-4 py-2">Return</th><th class="px-4 py-2">Order / shop</th><th class="px-4 py-2">Franchise</th>
                <th class="px-4 py-2">Reason</th><th class="px-4 py-2 text-right">Refund</th><th class="px-4 py-2">Status</th><th class="px-4 py-2">Age</th><th class="px-4 py-2"></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $r)
                <tr class="border-t align-top">
                    <td class="px-4 py-2">#{{ $r->id }}<span class="block text-xs text-gray-500">{{ $r->customer?->name }}</span></td>
                    <td class="px-4 py-2">{{ $r->order?->code }}<span class="block text-xs text-gray-500">{{ $r->store?->name }} · {{ ucfirst($r->order?->payment_method ?? '') }}</span></td>
                    <td class="px-4 py-2">{{ $r->franchise?->name ?? 'HQ' }}</td>
                    <td class="px-4 py-2">{{ \App\Models\MarketplaceReturn::REASONS[$r->reason] ?? $r->reason }}
                        <span class="block max-w-xs text-xs text-gray-600">{{ \Illuminate\Support\Str::limit($r->description, 120) }}</span>
                        <span class="mt-1 flex gap-1">@foreach ($r->photoUrls() as $src)<a href="{{ $src }}" target="_blank" rel="noopener"><img src="{{ $src }}" alt="Return photo" class="h-10 w-10 rounded object-cover"></a>@endforeach</span>
                    </td>
                    <td class="px-4 py-2 text-right tabular-nums">₹{{ number_format((float) $r->refund_amount, 2) }}<span class="block text-xs text-gray-500">to {{ $r->refund_destination === 'original' ? 'original method' : 'wallet' }}</span></td>
                    <td class="px-4 py-2">
                        <x-ui.badge :color="$r->status === 'refunded' ? 'green' : ($r->status === 'declined' ? 'gray' : 'amber')">{{ ucfirst($r->status) }}</x-ui.badge>
                        @if ($r->refund_status)<span class="block text-xs text-gray-500">Refund: {{ str_replace('_', ' ', $r->refund_status) }}</span>@endif
                        @if ($r->escalation_level > 0)<span class="block text-xs font-semibold text-red-700">Escalated to level {{ $r->escalation_level }}</span>@endif
                    </td>
                    <td class="px-4 py-2 text-xs text-gray-600">{{ $r->created_at->diffForHumans(short: true) }}</td>
                    <td class="px-4 py-2 text-right">
                        @if ($actions[$r->id] ?? null)
                            <x-ui.button variant="ghost" wire:click="open({{ $r->id }})">{{ ['decide' => 'Decide', 'receive' => 'Item received', 'request' => 'Request refund', 'approve' => 'Approve refund', 'retry' => 'Retry refund'][$actions[$r->id]] }}</x-ui.button>
                        @endif
                    </td>
                </tr>
                @if ($actingId === $r->id)
                    @php $a = $actions[$r->id] ?? null; @endphp
                    <tr class="bg-gray-50"><td colspan="8" class="px-4 py-3">
                        <div class="flex flex-wrap items-end gap-3">
                            @if ($a === 'decide')
                                <label class="text-sm">Refund (₹)<input type="text" inputmode="decimal" wire:model="amount" class="mt-1 block w-28 rounded border px-2 py-1"></label>
                            @endif
                            @if ($a !== 'receive' && $a !== 'retry')
                                <label class="flex-1 text-sm">{{ $a === 'decide' ? 'Note to the customer (required)' : 'Reason (required)' }}<input type="text" wire:model="note" class="mt-1 block w-full rounded border px-2 py-1"></label>
                            @endif
                            @switch($a)
                                @case('decide')
                                    <x-ui.button wire:click="act('accept')">Accept return</x-ui.button>
                                    <x-ui.button variant="danger" wire:click="act('decline')">Decline</x-ui.button>
                                    @break
                                @case('receive')
                                    <x-ui.button wire:click="act('receive')">The shop has the item back</x-ui.button>
                                    @break
                                @case('request')
                                    <x-ui.button wire:click="act('request')">Request refund</x-ui.button>
                                    @break
                                @case('approve')
                                    <x-ui.button wire:click="act('approve')">Approve</x-ui.button>
                                    <x-ui.button variant="danger" wire:click="act('reject')">Reject</x-ui.button>
                                    @break
                                @case('retry')
                                    <x-ui.button wire:click="act('retry')">Retry refund</x-ui.button>
                                    @break
                            @endswitch
                            <x-ui.button variant="secondary" wire:click="$set('actingId', null)">Close</x-ui.button>
                        </div>
                    </td></tr>
                @endif
            @empty
                <tr><td colspan="8" class="px-4 py-6 text-center text-gray-500">No returns here.</td></tr>
            @endforelse
        </tbody>
    </x-ui.table>
</div>
