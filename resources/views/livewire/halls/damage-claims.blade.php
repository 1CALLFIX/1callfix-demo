<div>
    @if (session('message'))
        <div class="mb-4 rounded bg-green-50 px-4 py-2 text-sm text-green-700" data-flash>{{ session('message') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-4 rounded bg-red-50 px-4 py-2 text-sm text-red-700" data-flash>{{ session('error') }}</div>
    @endif

    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <div>
            <h1 class="mb-1 text-2xl font-bold">Hall damage claims</h1>
            <p class="text-sm text-gray-500">A hall reported damage after a function, so the customer's deposit is held. Decide how much the hall gets; the rest goes back to the customer. Limits and second approval: <a href="{{ route('admin.refund-controls.index') }}" class="underline">Refund Controls</a>.</p>
        </div>
    </div>

    <x-ui.filter-tabs class="mb-4" :tabs="['waiting' => 'Waiting'.($waiting ? ' ('.$waiting.')' : ''), 'settled' => 'Settled']" :active="$filter" model="filter" />

    <div class="space-y-4">
        @forelse ($claims as $c)
            <?php
                $b = $c->booking;
                $action = $service->availableAction($c, auth()->user());
                $typed = $amounts[$c->id] ?? (string) (float) ($c->hall_amount ?? $c->asked_amount);
                $hall = is_numeric($typed) ? max(0, min((float) $c->deposit_amount, (float) $typed)) : 0;
                $age = $c->created_at->diffForHumans(null, true);
            ?>
            <x-ui.card wire:key="claim-{{ $c->id }}" data-testid="damage-claim">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="font-semibold">{{ $b?->hall?->name }} · <span class="font-mono text-xs">{{ $b?->code }}</span></p>
                        <p class="text-sm text-gray-500">{{ $b?->dateLabel('j M Y') }} · {{ $b?->customer_name }} ({{ $b?->customer_phone }}) · {{ $c->franchise?->name }}</p>
                        <p class="mt-2 rounded bg-amber-50 px-3 py-2 text-sm text-amber-900">"{{ $c->note }}"</p>
                    </div>
                    <div class="text-right text-sm">
                        <x-ui.badge>{{ \App\Models\HallDamageClaim::STATUS_LABELS[$c->status] ?? $c->status }}</x-ui.badge>
                        <p class="mt-1 text-gray-500">waiting {{ $age }} @if ($c->escalation_level > 0) · <span class="font-semibold text-red-700">escalated to level {{ $c->escalation_level }}</span> @endif</p>
                        <p class="mt-1">Deposit <b>₹{{ number_format((float) $c->deposit_amount) }}</b> · hall asked <b>₹{{ number_format((float) $c->asked_amount) }}</b></p>
                    </div>
                </div>

                @if ($c->status === \App\Models\HallDamageClaim::SETTLED)
                    <p class="mt-3 text-sm text-gray-700">Hall got ₹{{ number_format((float) $c->hall_amount, 2) }}; customer got back ₹{{ number_format((float) $c->deposit_amount - (float) $c->hall_amount, 2) }}. Decided by {{ $c->requestedBy?->name }} ({{ $c->request_reason }})@if ($c->approvedBy), approved by {{ $c->approvedBy->name }} ({{ $c->approval_reason }})@endif.</p>
                @else
                    @if ($c->status !== \App\Models\HallDamageClaim::OPEN)
                        <p class="mt-3 text-sm text-gray-700">Decision: hall gets ₹{{ number_format((float) $c->hall_amount, 2) }} — by {{ $c->requestedBy?->name }}: "{{ $c->request_reason }}"</p>
                    @endif
                    @if ($c->failure_message) <p class="mt-2 text-sm text-red-700">{{ $c->failure_message }}</p> @endif

                    @if ($action)
                        <div class="mt-4 grid gap-3 sm:grid-cols-3">
                            @if ($action === 'decide')
                                <label class="text-sm">Hall gets ₹
                                    <input type="number" min="0" max="{{ (float) $c->deposit_amount }}" step="1" wire:model.live.debounce.400ms="amounts.{{ $c->id }}" placeholder="{{ (float) $c->asked_amount }}" class="mt-1 w-full rounded border-gray-300 text-base font-semibold">
                                </label>
                            @endif
                            <label class="text-sm sm:col-span-2">Reason (required)
                                <input type="text" wire:model="reasons.{{ $c->id }}" maxlength="500" placeholder="e.g. Checked photos with the owner, 4 chairs broken" class="mt-1 w-full rounded border-gray-300 text-sm">
                            </label>
                        </div>
                        @if ($action === 'decide')
                            <p class="mt-2 text-sm" data-testid="damage-split">Hall gets <b class="text-emerald-700">₹{{ number_format($hall, 2) }}</b> · Customer gets back <b class="text-blue-700">₹{{ number_format((float) $c->deposit_amount - $hall, 2) }}</b></p>
                        @endif
                        <div class="mt-3 flex flex-wrap gap-2">
                            @if ($action === 'decide')
                                <x-ui.button wire:click="decide({{ $c->id }})" wire:confirm="Hall gets ₹{{ number_format($hall, 2) }}, the customer gets back ₹{{ number_format((float) $c->deposit_amount - $hall, 2) }}. Continue?">Decide</x-ui.button>
                            @elseif ($action === 'approve')
                                <x-ui.button wire:click="approve({{ $c->id }})">Approve</x-ui.button>
                                <x-ui.button variant="danger" wire:click="reject({{ $c->id }})">Reject</x-ui.button>
                            @elseif ($action === 'retry')
                                <x-ui.button wire:click="retry({{ $c->id }})">Retry</x-ui.button>
                            @endif
                        </div>
                    @else
                        <p class="mt-3 text-sm text-gray-500">{{ $c->status === \App\Models\HallDamageClaim::AWAITING_APPROVAL && $c->requested_by_id === auth()->id() ? 'Waiting for a different person to approve your decision.' : 'Above your limit or outside your area: someone else decides this one.' }}</p>
                    @endif
                @endif
            </x-ui.card>
        @empty
            <x-ui.card><p class="text-sm text-gray-500">No claims here.</p></x-ui.card>
        @endforelse
        {{ $claims->links() }}
    </div>
</div>
