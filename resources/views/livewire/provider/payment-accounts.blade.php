<div>
    <h1 class="text-xl font-bold tracking-tight">Payments</h1>

    {{-- Earnings / Payment Accounts live under this one nav branch — see
         the matching strip in earnings.blade.php. --}}
    <div class="mt-3 flex gap-2 text-sm">
        <a href="{{ route('provider.earnings') }}" wire:navigate
           class="rounded-lg px-3 py-1.5 font-medium bg-white text-slate-700 border border-slate-300">Earnings</a>
        <a href="{{ route('provider.payment-accounts') }}" wire:navigate
           class="rounded-lg px-3 py-1.5 font-medium bg-slate-900 text-white">Payment Accounts</a>
    </div>

    @if ($flashMessage)
        <div @class(['mt-4 rounded-lg p-3 text-sm', 'bg-emerald-50 text-emerald-700' => $flashType === 'success', 'bg-red-50 text-red-700' => $flashType === 'error'])>
            {{ $flashMessage }}
        </div>
    @endif

    <div class="mt-4 space-y-2">
        @forelse ($accounts as $account)
            <x-ui.card class="!p-4" wire:key="account-{{ $account->id }}">
                <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold">
                            {{ strtoupper($account->account_type) }} — {{ $account->masked_account_number }}
                        </p>
                        <p class="mt-1 text-xs text-slate-500">
                            {{ $account->account_holder_name ?: '—' }}
                        </p>
                        <div class="mt-2 flex gap-1.5">
                            <x-ui.badge :color="$account->is_verified ? 'green' : 'amber'">
                                {{ $account->is_verified ? 'Verified' : 'Pending verification' }}
                            </x-ui.badge>
                            @if ($account->is_default)
                                <x-ui.badge color="gray">Default</x-ui.badge>
                            @endif
                        </div>
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-1 text-sm">
                        <div class="flex gap-2">
                            <x-ui.button variant="ghost" wire:click="startEdit({{ $account->id }})">Edit</x-ui.button>
                            <x-ui.button variant="ghost" color="red" wire:click="delete({{ $account->id }})" wire:confirm="Remove this payment account?">Delete</x-ui.button>
                        </div>
                        @unless ($account->is_default)
                            <x-ui.button variant="ghost" wire:click="setDefault({{ $account->id }})">Set as default</x-ui.button>
                        @endunless
                    </div>
                </div>
            </x-ui.card>
        @empty
            <x-ui.card class="!p-6 text-center text-sm text-slate-500">
                No payment accounts yet. Add a bank account or UPI ID so we know where to send your payouts.
            </x-ui.card>
        @endforelse
    </div>

    <div class="mt-4">
        @if (! $showForm)
            <x-ui.button wire:click="startCreate">+ Add payment account</x-ui.button>
        @else
            <x-ui.card class="!p-4">
                <h2 class="text-sm font-semibold">{{ $editingId ? 'Edit payment account' : 'Add payment account' }}</h2>

                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs font-medium">Account type</label>
                        <select wire:model.live="accountType" class="w-full rounded border px-3 py-2 text-sm">
                            <option value="upi">UPI</option>
                            <option value="bank">Bank account</option>
                        </select>
                    </div>

                    @if ($accountType === 'bank')
                        <div>
                            <label class="mb-1 block text-xs font-medium">Account holder name</label>
                            <input type="text" wire:model="accountHolderName" class="w-full rounded border px-3 py-2 text-sm">
                            @error('accountHolderName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium">Account number</label>
                            <input type="text" wire:model="accountNumber" class="w-full rounded border px-3 py-2 text-sm">
                            @error('accountNumber') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium">IFSC</label>
                            <input type="text" wire:model="ifsc" class="w-full rounded border px-3 py-2 text-sm">
                            @error('ifsc') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    @else
                        <div>
                            <label class="mb-1 block text-xs font-medium">UPI ID</label>
                            <input type="text" wire:model="upiId" placeholder="name@bank" class="w-full rounded border px-3 py-2 text-sm">
                            @error('upiId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    <label class="flex items-center gap-2 text-xs font-medium sm:col-span-2">
                        <input type="checkbox" wire:model="isDefault">
                        Make this my default payout account
                    </label>
                </div>

                <p class="mt-3 text-xs text-slate-500">
                    New and edited accounts need admin verification before they can receive a payout.
                </p>

                <div class="mt-4 flex gap-2">
                    <x-ui.button wire:click="save">{{ $editingId ? 'Save changes' : 'Add account' }}</x-ui.button>
                    <x-ui.button variant="secondary" wire:click="cancelForm">Cancel</x-ui.button>
                </div>
            </x-ui.card>
        @endif
    </div>
</div>
