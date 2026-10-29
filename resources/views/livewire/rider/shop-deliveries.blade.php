<div class="space-y-4" wire:poll.5s
     x-data="{ beat: null }"
     x-init="if (navigator.geolocation) { const send = () => navigator.geolocation.getCurrentPosition((p) => $wire.heartbeat(p.coords.latitude, p.coords.longitude), () => {}, { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 }); send(); beat = setInterval(send, 120000); }"
     x-on:livewire:navigating.window="clearInterval(beat)">
    <h1 class="text-xl font-bold tracking-tight">Shop deliveries</h1>

    @if ($notice)<div role="status" class="rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800">{{ $notice }}</div>@endif
    @if ($error)<div role="alert" class="rounded-xl bg-red-50 p-3 text-sm text-red-800">{{ $error }}</div>@endif

    @if ($job)
        @php $cash = $job->payment_method === 'cash'; @endphp
        <section class="rounded-2xl border-2 border-blue-600 bg-white p-4">
            <p class="text-xs font-bold uppercase tracking-widest text-blue-700">Your delivery · {{ $job->code }}</p>

            <div class="mt-3">
                <p class="text-xs font-semibold uppercase text-slate-500">1. Collect from the shop</p>
                <p class="font-semibold">{{ $job->store->name }}</p>
                <p class="text-sm text-slate-600">{{ $job->store->address_line }}</p>
                <div class="mt-1 flex gap-4 text-sm">
                    <a href="https://www.google.com/maps/dir/?api=1&destination={{ $job->store->lat }},{{ $job->store->lng }}" target="_blank" rel="noopener" class="font-medium text-blue-700">Directions</a>
                    @if ($job->store->phone)<a href="tel:{{ $job->store->phone }}" class="font-medium text-blue-700">Call the shop</a>@endif
                </div>
                <p class="mt-1 text-xs text-slate-500">{{ $job->items->sum('quantity') }} items. Check the count before you leave.</p>
            </div>

            <div class="mt-4 border-t border-slate-100 pt-3">
                <p class="text-xs font-semibold uppercase text-slate-500">2. Deliver to</p>
                <p class="font-semibold">{{ $job->customer?->name }}</p>
                <p class="text-sm text-slate-600">{{ $job->deliveryAddress?->address_line }}@if ($job->deliveryAddress?->landmark), {{ $job->deliveryAddress->landmark }}@endif</p>
                <div class="mt-1 flex gap-4 text-sm">
                    @if ($job->deliveryAddress)<a href="https://www.google.com/maps/dir/?api=1&destination={{ $job->deliveryAddress->lat }},{{ $job->deliveryAddress->lng }}" target="_blank" rel="noopener" class="font-medium text-blue-700">Directions</a>@endif
                    @if ($job->customer?->phone)<a href="tel:{{ $job->customer->phone }}" class="font-medium text-blue-700">Call the customer</a>@endif
                </div>
                @if ($job->special_instructions)<p class="mt-1 text-sm text-slate-700">Note: {{ $job->special_instructions }}</p>@endif
            </div>

            <div @class(['mt-4 rounded-xl p-3 text-sm', 'bg-amber-50 text-amber-900' => $cash, 'bg-emerald-50 text-emerald-800' => ! $cash])>
                @if ($cash)
                    <p class="font-bold">Collect {{ $currencySymbol }}{{ number_format((float) $job->total_amount, 2) }} in cash before you hand it over.</p>
                @else
                    <p class="font-bold">Prepaid. Collect nothing.</p>
                @endif
            </div>

            <form wire:submit="deliver" class="mt-4">
                <label for="shop-otp" class="block text-sm font-medium">3. Customer's code</label>
                <div class="mt-1 flex gap-2">
                    <input id="shop-otp" type="text" inputmode="numeric" maxlength="8" wire:model="otp" class="min-h-12 w-40 rounded-xl border border-slate-300 px-3 text-lg tracking-widest">
                    <button type="submit" class="min-h-12 flex-1 rounded-xl bg-blue-600 font-bold text-white">Delivered</button>
                </div>
            </form>
        </section>
    @elseif ($busyWithParcel)
        <p class="rounded-2xl border border-slate-200 bg-white p-4 text-sm text-slate-700">You are on a parcel right now. Shop offers come once it is delivered. <a href="{{ route('rider.dashboard') }}" wire:navigate class="font-medium text-blue-700">Go to the parcel</a></p>
    @elseif (! $rider->is_online)
        <p class="rounded-2xl border border-slate-200 bg-white p-4 text-sm text-slate-700">You are offline. <a href="{{ route('rider.dashboard') }}" wire:navigate class="font-medium text-blue-700">Go online on Home</a> to get shop deliveries.</p>
    @elseif ($offers->isEmpty())
        <p class="rounded-2xl border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-600">No shop deliveries right now. New ones ring here.</p>
    @else
        @foreach ($offers as $offer)
            @php $o = $offer->dispatchable; @endphp
            <section class="rounded-2xl border-2 border-amber-400 bg-amber-50 p-4" wire:key="shop-offer-{{ $o->id }}">
                <p class="text-sm font-bold">{{ $o->store?->name }} → {{ $o->deliveryAddress?->city ?: \Illuminate\Support\Str::limit((string) $o->deliveryAddress?->address_line, 40) }}</p>
                <p class="text-xs text-slate-600">{{ $o->code }}@if ($offer->distance_km !== null) · {{ rtrim(rtrim(number_format((float) $offer->distance_km, 1), '0'), '.') }} km to the shop @endif</p>
                <p class="mt-1 text-sm">{{ $o->payment_method === 'cash' ? 'Cash: collect '.$currencySymbol.number_format((float) $o->total_amount, 2) : 'Prepaid' }}</p>
                <div class="mt-3 flex gap-2">
                    <button type="button" wire:click="accept({{ $o->id }})" class="min-h-11 flex-1 rounded-xl bg-emerald-600 font-bold text-white">Accept</button>
                    <button type="button" wire:click="decline({{ $o->id }})" class="min-h-11 rounded-xl border border-slate-300 bg-white px-4 text-sm">Decline</button>
                </div>
            </section>
        @endforeach
    @endif
</div>
