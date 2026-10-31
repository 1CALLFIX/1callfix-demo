<div>
    <div class="mb-4">
        <h1 class="text-2xl font-bold">Parcel Claims</h1>
        <p class="text-sm text-gray-500">Protected parcels reported lost or damaged. Payouts go to the customer's wallet and follow the approval model (Refund Controls → Parcel protection claims).</p>
    </div>

    @if ($flashMessage)
        <div @class(['mb-4 rounded px-4 py-2 text-sm', 'bg-green-50 text-green-700' => $flashType === 'success', 'bg-red-50 text-red-700' => $flashType !== 'success'])>{{ $flashMessage }}</div>
    @endif

    <x-ui.filter-tabs class="mb-3" :tabs="['open' => 'Open', 'paid' => 'Paid', 'declined' => 'Declined', 'all' => 'All']" :active="$statusFilter" model="statusFilter" />

    <x-ui.table>
        <x-slot:footer>{{ $rows->links() }}</x-slot:footer>
        <thead class="bg-gray-50 text-left text-gray-500">
            <tr>
                <th class="px-4 py-2">Claim</th><th class="px-4 py-2">Parcel</th><th class="px-4 py-2">Franchise</th>
                <th class="px-4 py-2">Reason</th><th class="px-4 py-2 text-right">Amount</th><th class="px-4 py-2">Status</th><th class="px-4 py-2">Age</th><th class="px-4 py-2"></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $c)
                <tr class="border-t align-top">
                    <td class="px-4 py-2">#{{ $c->id }}<span class="block text-xs text-gray-500">{{ $c->customer?->name }}</span></td>
                    <td class="px-4 py-2">{{ $c->order?->code }}<span class="block text-xs text-gray-500">Declared ₹{{ number_format((float) $c->order?->declared_value, 2) }} · fee ₹{{ number_format((float) $c->order?->protection_fee, 2) }}</span></td>
                    <td class="px-4 py-2">{{ $c->franchise?->name ?? 'HQ' }}</td>
                    <td class="px-4 py-2">{{ \App\Models\ParcelProtectionClaim::REASONS[$c->reason] ?? $c->reason }}
                        <span class="block max-w-xs text-xs text-gray-600">{{ \Illuminate\Support\Str::limit($c->description, 120) }}</span>
                        <span class="mt-1 flex gap-1">
                            @foreach ($c->photos ?? [] as $i => $path)
                                <a href="{{ route('admin.parcel-claims.photo', [$c->id, $i]) }}" target="_blank" rel="noopener" class="text-xs text-blue-700 underline">Photo {{ $i + 1 }}</a>
                            @endforeach
                        </span>
                    </td>
                    <td class="px-4 py-2 text-right tabular-nums">₹{{ number_format((float) $c->payout_amount, 2) }}
                        @if ((float) $c->payout_amount !== (float) $c->amount_claimed)<span class="block text-xs text-gray-500">claimed ₹{{ number_format((float) $c->amount_claimed, 2) }}</span>@endif
                    </td>
                    <td class="px-4 py-2">
                        <x-ui.badge :color="$c->status === 'paid' ? 'green' : ($c->status === 'declined' ? 'gray' : 'amber')">{{ ucfirst($c->status) }}</x-ui.badge>
                        @if ($c->payout_status)<span class="block text-xs text-gray-500">Payout: {{ str_replace('_', ' ', $c->payout_status) }}</span>@endif
                        @if ($c->escalation_level > 0)<span class="block text-xs font-semibold text-red-700">Escalated to level {{ $c->escalation_level }}</span>@endif
                    </td>
                    <td class="px-4 py-2 text-xs text-gray-600">{{ $c->created_at->diffForHumans(short: true) }}</td>
                    <td class="px-4 py-2 text-right">
                        @if ($actions[$c->id] ?? null)
                            <x-ui.button variant="ghost" wire:click="open({{ $c->id }})">{{ ['decide' => 'Decide', 'request' => 'Request payout', 'approve' => 'Approve payout', 'retry' => 'Retry payout'][$actions[$c->id]] }}</x-ui.button>
                        @endif
                    </td>
                </tr>
                @if ($actingId === $c->id)
                    @php $a = $actions[$c->id] ?? null; @endphp
                    <tr class="bg-gray-50"><td colspan="8" class="px-4 py-3">
                        <div class="flex flex-wrap items-end gap-3">
                            @if ($a === 'decide')
                                <label class="text-sm">Payout (₹)<input type="text" inputmode="decimal" wire:model="amount" class="mt-1 block w-28 rounded border px-2 py-1"></label>
                            @endif
                            @if ($a !== 'retry')
                                <label class="flex-1 text-sm">{{ $a === 'decide' ? 'Note to the customer (required)' : 'Reason (required)' }}<input type="text" wire:model="note" class="mt-1 block w-full rounded border px-2 py-1"></label>
                            @endif
                            @switch($a)
                                @case('decide')
                                    <x-ui.button wire:click="act('accept')">Accept claim</x-ui.button>
                                    <x-ui.button variant="danger" wire:click="act('decline')">Decline</x-ui.button>
                                    @break
                                @case('request')
                                    <x-ui.button wire:click="act('request')">Request payout</x-ui.button>
                                    @break
                                @case('approve')
                                    <x-ui.button wire:click="act('approve')">Approve</x-ui.button>
                                    <x-ui.button variant="danger" wire:click="act('reject')">Reject</x-ui.button>
                                    @break
                                @case('retry')
                                    <x-ui.button wire:click="act('retry')">Retry payout</x-ui.button>
                                    @break
                            @endswitch
                            <x-ui.button variant="secondary" wire:click="$set('actingId', null)">Close</x-ui.button>
                        </div>
                    </td></tr>
                @endif
            @empty
                <tr><td colspan="8" class="px-4 py-6 text-center text-gray-500">No claims here.</td></tr>
            @endforelse
        </tbody>
    </x-ui.table>
</div>
