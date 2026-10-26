{{-- The Payments tab strip shared by the partner and rider areas (riders reuse the partner Payment Accounts and
     Request Payout screens; see App\Livewire\Rider\PaymentAccounts / RequestPayout). `current` is passed in, not
     read from the URL: a Livewire action re-renders on /livewire/update, where routeIs() would match nothing. --}}
@php
    $tabs = ($area ?? 'provider') === 'rider'
        ? ['rider.earnings' => 'Earnings', 'rider.payment-accounts' => 'Payment Accounts', 'rider.request-payout' => 'Request Payout']
        : ['provider.earnings' => 'Earnings', 'provider.payment-accounts' => 'Payment Accounts', 'provider.request-payout' => 'Request Payout', 'provider.wallet' => 'Wallet & dues'];
    $currentRoute = ($area ?? 'provider').'.'.$current;
@endphp
<div class="mt-3 flex flex-wrap gap-2 text-sm">
    @foreach ($tabs as $tabRoute => $tabLabel)
        <a href="{{ route($tabRoute) }}" wire:navigate
           @class(['rounded-lg px-3 py-1.5 font-medium',
                   'bg-slate-900 text-white' => $tabRoute === $currentRoute,
                   'bg-white text-slate-700 border border-slate-300' => $tabRoute !== $currentRoute])>{{ $tabLabel }}</a>
    @endforeach
</div>
