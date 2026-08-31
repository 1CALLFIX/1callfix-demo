{{--
    Cart checkout: one address, a time per service, a server-priced review,
    then payment. On confirm the whole cart goes to CreateBookingBundleAction
    as one bundle. No price is entered or sent here.
--}}
<div class="mx-auto max-w-2xl px-4 py-8 sm:px-6 lg:px-8 mb-bottom-nav">

    <a href="{{ route('customer.cart') }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-900">
        <x-icon name="arrow-left" class="h-4 w-4" /> Cart
    </a>

    <h1 class="mt-3 text-2xl font-bold tracking-tight text-slate-900">Checkout</h1>

    {{-- Step indicator --}}
    <ol class="mt-4 flex items-center gap-2 text-xs font-medium">
        @foreach ($steps as $i => $s)
            @php $active = $s === $step; $done = array_search($step, $steps, true) > $i; @endphp
            <li @class([
                'rounded-full px-3 py-1',
                'bg-blue-600 text-white' => $active,
                'bg-slate-100 text-slate-500' => ! $active && ! $done,
                'bg-emerald-100 text-emerald-800' => $done,
            ])>{{ ucfirst($s) }}</li>
        @endforeach
    </ol>

    @if ($error)
        <div role="alert" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $error }}</div>
    @endif

    <div class="mt-6 rounded-xl border border-slate-200 p-4">

        {{-- ---------------------------------------------- address --}}
        @if ($step === 'address')
            <h2 class="text-sm font-semibold text-slate-900">Service address</h2>
            <p class="mt-1 text-xs text-slate-500">One address for every service in this cart.</p>

            @if ($addresses->isNotEmpty())
                <div class="mt-3 space-y-2">
                    @foreach ($addresses as $address)
                        <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-slate-200 p-3 has-[:checked]:border-blue-600 has-[:checked]:bg-blue-50/40">
                            <input type="radio" wire:model="addressId" value="{{ $address->id }}" class="mt-1">
                            <span class="text-sm">
                                <span class="font-medium text-slate-900">{{ $address->label }}</span>
                                <span class="block text-slate-600">{{ $address->address_line }}{{ $address->city ? ', '.$address->city : '' }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
            @else
                <p class="mt-3 text-sm text-slate-600">You have no saved addresses yet.</p>
            @endif

            <button type="button" wire:click="$toggle('addingAddress')" class="mt-3 text-sm font-medium text-slate-700 underline underline-offset-2">
                {{ $addingAddress ? 'Cancel' : 'Add a new address' }}
            </button>

            @if ($addingAddress)
                <div class="mt-3 space-y-2 rounded-lg bg-slate-50 p-3">
                    <input wire:model="newAddress.label" placeholder="Label (Home, Office)" class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    @error('newAddress.label') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                    <input wire:model="newAddress.address_line" placeholder="Address line" class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    @error('newAddress.address_line') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                    <input wire:model="newAddress.landmark" placeholder="Landmark (optional)" class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <input wire:model="newAddress.city" placeholder="City (optional)" class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <input wire:model="newAddress.pincode" placeholder="PIN code (optional)" class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <button type="button" wire:click="saveNewAddress" class="inline-flex min-h-10 items-center rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white">Save address</button>
                </div>
            @endif

        {{-- ---------------------------------------------- schedule --}}
        @elseif ($step === 'schedule')
            <h2 class="text-sm font-semibold text-slate-900">When should each service happen?</h2>
            <p class="mt-1 text-xs text-slate-500">Leave a time blank for as-soon-as-possible.</p>

            <ul class="mt-3 space-y-3">
                @foreach ($lines as $row)
                    @php $item = $row['item']; @endphp
                    <li wire:key="sched-{{ $item->id }}">
                        <label for="co-sched-{{ $item->id }}" class="block text-sm font-medium text-slate-800">{{ $item->service->name }}</label>
                        <input type="datetime-local" id="co-sched-{{ $item->id }}" wire:model.blur="schedules.{{ $item->id }}"
                               class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline focus:outline-2 focus:outline-offset-0 focus:outline-blue-600">
                    </li>
                @endforeach
            </ul>

        {{-- ---------------------------------------------- review --}}
        @elseif ($step === 'review')
            <h2 class="text-sm font-semibold text-slate-900">Review</h2>
            <ul class="mt-3 divide-y divide-slate-100">
                @foreach ($lines as $row)
                    @php $item = $row['item']; @endphp
                    <li class="flex items-center justify-between gap-3 py-2 text-sm">
                        <span class="min-w-0">
                            <span class="block truncate font-medium text-slate-900">{{ $item->service->name }}</span>
                            <span class="block text-xs text-slate-500">
                                &times;{{ $item->quantity }} ·
                                {{ ($schedules[$item->id] ?? '') !== '' ? \Illuminate\Support\Carbon::parse($schedules[$item->id])->format('j M, g:i A') : 'ASAP' }}
                            </span>
                        </span>
                        <span class="shrink-0 font-semibold text-slate-900">{{ $currencySymbol }}{{ number_format($row['line'], 2) }}</span>
                    </li>
                @endforeach
            </ul>
            <div class="mt-3 flex items-baseline justify-between border-t border-slate-200 pt-3">
                <span class="text-sm font-medium text-slate-700">Total</span>
                <span class="text-xl font-bold text-slate-900">{{ $currencySymbol }}{{ number_format($reviewTotal, 2) }}</span>
            </div>
            <p class="mt-1 text-xs text-slate-500">This is the amount you will be charged.</p>

        {{-- ---------------------------------------------- pay --}}
        @elseif ($step === 'pay')
            <h2 class="text-sm font-semibold text-slate-900">Payment</h2>

            @if (empty($enabledMethods))
                <p class="mt-2 text-sm text-rose-600">No payment method is available for this address.</p>
            @else
                <div class="mt-3 space-y-2">
                    @foreach ($enabledMethods as $value => $label)
                        <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-slate-200 p-3 has-[:checked]:border-blue-600 has-[:checked]:bg-blue-50/40">
                            <input type="radio" wire:model="paymentMethod" value="{{ $value }}">
                            <span class="text-sm text-slate-900">{{ $label }}</span>
                            @if ($value === 'wallet')
                                <span class="ml-auto text-xs text-slate-500">Balance {{ $currencySymbol }}{{ number_format($walletBalance, 2) }}</span>
                            @endif
                        </label>
                    @endforeach
                </div>

                <button type="button" wire:click="place" wire:loading.attr="disabled"
                        class="mt-4 flex min-h-12 w-full items-center justify-center rounded-lg bg-blue-600 px-6 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:opacity-50">
                    Confirm &amp; book · {{ $currencySymbol }}{{ number_format($reviewTotal, 2) }}
                </button>
            @endif
        @endif
    </div>

    {{-- nav --}}
    <div class="mt-4 flex items-center justify-between">
        <button type="button" wire:click="back" @disabled($step === $steps[0])
                class="text-sm font-medium text-slate-500 disabled:opacity-40">Back</button>
        @unless ($step === 'pay')
            <button type="button" wire:click="next"
                    class="inline-flex min-h-11 items-center rounded-lg bg-blue-600 px-5 text-sm font-semibold text-white transition hover:bg-blue-700">
                Continue
            </button>
        @endunless
    </div>
</div>
