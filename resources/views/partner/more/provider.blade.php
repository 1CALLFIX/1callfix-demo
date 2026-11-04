@php
    // Partner apps 2026-11-04: the service partner's More tab.
    $user = auth()->user();
    $provider = $user->providerProfile;
@endphp
<x-layouts.provider title="More">
    <x-partner.more-menu
        :name="$user->name"
        :subtitle="'Partner ID '.$provider?->id.($user->phone ? ' · '.$user->phone : '')"
        mode="service"
        logout-route="provider.logout"
        :tiles="[
            ['Wallet & dues', route('provider.wallet'), 'wallet'],
            ['Bank & UPI', route('provider.payment-accounts'), 'banknotes'],
            ['Get paid', route('provider.request-payout'), 'arrow-right'],
        ]"
        :rows="array_values(array_filter([
            ['Job history', route('provider.history'), 'clock'],
            ['Activity', route('provider.activity'), 'activity'],
            \App\Services\Providers\ShiftSchedule::mode() !== 'off' ? ['My shifts', route('provider.shifts'), 'clock'] : null,
        ]))" />
</x-layouts.provider>
