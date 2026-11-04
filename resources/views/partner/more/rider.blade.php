@php
    // Partner apps 2026-11-04: the rider's More tab.
    $user = auth()->user();
    $rider = \App\Models\FieldWorker::where('user_id', $user->id)->firstOrFail();
    $symbol = \App\Models\Setting::get('locale.currency_symbol', '₹');
    $dues = round(app(\App\Services\Parcel\FieldWorkerCashDueService::class)->outstanding($rider)
        + app(\App\Services\Marketplace\MarketplaceSettlementService::class)->outstanding('field_worker', $rider->id), 2);
@endphp
<x-layouts.rider title="More">
    <x-partner.more-menu
        :name="$user->name"
        :subtitle="'Rider ID '.$rider->id.($user->phone ? ' · '.$user->phone : '')"
        mode="parcel"
        logout-route="rider.logout"
        :tiles="[
            ['Cash dues', route('rider.earnings'), 'wallet', $dues > 0 ? $symbol.number_format($dues, 2) : null],
            ['Bank & UPI', route('rider.payment-accounts'), 'banknotes'],
            ['Get paid', route('rider.request-payout'), 'arrow-right'],
        ]" />
</x-layouts.rider>
