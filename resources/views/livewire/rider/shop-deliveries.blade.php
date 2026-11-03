<div class="space-y-4" wire:poll.5s
     x-data="{ beat: null }"
     x-init="if (navigator.geolocation) { const send = () => navigator.geolocation.getCurrentPosition((p) => $wire.heartbeat(p.coords.latitude, p.coords.longitude), () => {}, { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 }); send(); beat = setInterval(send, 120000); }"
     x-on:livewire:navigating.window="clearInterval(beat)">
    {{-- Rider app 2026-11-04: Home runs every job now; this page stays for old links. --}}
    <h1 class="text-xl font-bold tracking-tight">Shop &amp; food deliveries</h1>
    <p class="text-sm text-slate-600">Every job — parcel, shop and food — is on <a href="{{ route('rider.dashboard') }}" wire:navigate class="font-medium text-blue-700">Home</a> now.</p>

    @if ($notice)<div role="status" class="rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800">{{ $notice }}</div>@endif
    @if ($error)<div role="alert" class="rounded-xl bg-red-50 p-3 text-sm text-red-800">{{ $error }}</div>@endif

    @if ($returnJob)
        @include('livewire.rider.partials.return-job', ['otpModel' => 'otp', 'collectAction' => 'collectReturn', 'dropAction' => 'dropReturn'])
    @elseif ($job)
        @include('livewire.rider.partials.shop-job', ['otpModel' => 'otp', 'deliverAction' => 'deliver'])
    @elseif ($busyWithParcel)
        <p class="rounded-2xl border border-slate-200 bg-white p-4 text-sm text-slate-700">You are on a parcel right now. New offers come once it is delivered. <a href="{{ route('rider.dashboard') }}" wire:navigate class="font-medium text-blue-700">Go to the parcel</a></p>
    @elseif (! $rider->is_online)
        <p class="rounded-2xl border border-slate-200 bg-white p-4 text-sm text-slate-700">You are offline. <a href="{{ route('rider.dashboard') }}" wire:navigate class="font-medium text-blue-700">Go online on Home</a> to get deliveries.</p>
    @elseif ($offers->isEmpty() && $pickups->isEmpty())
        <p class="rounded-2xl border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-600">No shop or food deliveries right now. New ones ring here.</p>
    @else
        @include('livewire.rider.partials.shop-offers', ['shopOffers' => $offers, 'acceptAction' => 'accept', 'declineAction' => 'decline', 'acceptPickupAction' => 'acceptPickup'])
    @endif
</div>
