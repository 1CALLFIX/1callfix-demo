<div>
    <h1 class="text-2xl font-bold mb-4">KYC / Withdrawal Restriction Support Requests</h1>
    <p class="text-xs text-gray-400 mb-4">Franchise raises a request; only Central Admin can approve, reject, or ask for more information. A Franchise never gets a direct "enable withdrawal" control.</p>

    @if ($flashMessage)
        <div @class(['rounded px-4 py-2 mb-4 text-sm', 'bg-green-50 text-green-700' => $flashType === 'success', 'bg-red-50 text-red-700' => $flashType === 'error'])>
            {{ $flashMessage }}
        </div>
    @endif

    @unless ($canDecide)
        <x-ui.card title="Raise a new request" class="mb-6">
            <div class="relative w-72 mb-3">
                <input type="text" wire:model.live.debounce.300ms="providerSearch" placeholder="Search provider by name…" class="w-full border rounded px-3 py-2 text-sm">
                @error('providerId')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                @if ($this->matchingProviders->isNotEmpty())
                    <div class="absolute z-10 bg-white border rounded shadow-sm w-full mt-1">
                        @foreach ($this->matchingProviders as $p)
                            <button type="button" wire:click="selectProvider({{ $p->id }})" class="block w-full text-left px-3 py-1.5 text-sm hover:bg-gray-50">{{ $p->user->name ?? "#{$p->id}" }}</button>
                        @endforeach
                    </div>
                @endif
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div class="md:col-span-2"><label class="block text-xs font-medium mb-1">Reason</label><textarea wire:model="reason" rows="2" class="w-full border rounded px-3 py-2 text-sm"></textarea>@error('reason')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror</div>
                <div><label class="block text-xs font-medium mb-1">Urgency</label>
                    <select wire:model="urgency" class="w-full border rounded px-3 py-2 text-sm"><option value="low">Low</option><option value="normal">Normal</option><option value="high">High</option></select>
                </div>
            </div>
            <div class="mt-3"><label class="block text-xs font-medium mb-1">Assistance already provided</label><textarea wire:model="assistanceProvided" rows="2" class="w-full border rounded px-3 py-2 text-sm"></textarea></div>
            <div class="flex justify-end pt-4 mt-4 border-t">
                <x-ui.button wire:click="create">Raise Request</x-ui.button>
            </div>
        </x-ui.card>
    @endunless

    <div class="space-y-3">
        @foreach ($requests as $r)
            <x-ui.card>
                <div class="flex items-center justify-between">
                    <div>
                        <span class="font-semibold">{{ $r->provider->user->name ?? "Provider #{$r->provider_id}" }}</span>
                        <span class="text-gray-400 text-xs ml-2">{{ $r->franchise->name ?? '—' }}</span>
                        <x-ui.badge class="ml-2" :color="match($r->status) { 'approved' => 'green', 'rejected' => 'red', 'more_info_requested' => 'amber', 'closed' => 'slate', default => 'gray' }">{{ ucfirst(str_replace('_', ' ', $r->status)) }}</x-ui.badge>
                    </div>
                    <span class="text-xs text-gray-400">{{ ucfirst($r->urgency) }} urgency &middot; {{ app(\App\Services\TimezoneResolver::class)->format($r->created_at, $r->franchise, 'd M Y') }}</span>
                </div>
                <p class="text-sm text-gray-600 mt-2">{{ $r->reason }}</p>
                @if ($r->assistance_provided)<p class="text-xs text-gray-400 mt-1">Assistance: {{ $r->assistance_provided }}</p>@endif
                @if ($r->decision_note)<p class="text-xs text-gray-500 mt-1 italic">Decision note: {{ $r->decision_note }}</p>@endif

                @if ($canDecide && $r->status === 'open')
                    <div class="flex gap-2 mt-3">
                        <x-ui.button variant="ghost" wire:click="startDeciding({{ $r->id }})">Decide</x-ui.button>
                    </div>
                    @if ($decidingRequestId === $r->id)
                        <div class="mt-3 pt-3 border-t">
                            <textarea wire:model="decisionNote" rows="2" placeholder="Decision note…" class="w-full border rounded px-3 py-2 text-sm mb-2"></textarea>
                            <input type="number" wire:model="exceptionDays" placeholder="Exception valid for N days (blank = until revoked)" class="w-64 border rounded px-3 py-2 text-sm mb-2">
                            <div class="flex gap-2">
                                <x-ui.button size="sm" variant="success" wire:click="decide({{ $r->id }}, 'approved')" wire:confirm="Approve and grant a withdrawal exception?">Approve (grant exception)</x-ui.button>
                                <button type="button" wire:click="decide({{ $r->id }}, 'more_info_requested')" class="bg-amber-500 text-white px-4 py-1.5 rounded text-xs">Request more info</button>
                                <x-ui.button size="sm" variant="danger" wire:click="decide({{ $r->id }}, 'rejected')">Reject</x-ui.button>
                            </div>
                        </div>
                    @endif
                @endif
            </x-ui.card>
        @endforeach
        @if ($requests->isEmpty())
            <p class="text-sm text-gray-400">No support requests yet.</p>
        @endif
    </div>
    <div class="mt-4">{{ $requests->links() }}</div>
</div>
