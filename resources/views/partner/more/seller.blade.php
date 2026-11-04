@php
    // Partner apps 2026-11-04: the seller / restaurant More tab. Same shop the other seller pages pick (first by id).
    $user = auth()->user();
    $store = \App\Models\Store::whereHas('provider', fn ($q) => $q->where('user_id', $user->id))->orderBy('id')->firstOrFail();
    $isRestaurant = $store->isRestaurant();
@endphp
<x-layouts.seller title="More">
    <x-partner.more-menu
        :name="$store->name"
        :subtitle="$user->name.($user->phone ? ' · '.$user->phone : '')"
        mode="commerce"
        logout-route="seller.logout"
        :tiles="[
            [$isRestaurant ? 'Restaurant details' : 'Shop details', route('seller.shop'), 'building'],
            ['Bank & UPI', route('seller.payment-accounts'), 'banknotes'],
            ['Get paid', route('seller.request-payout'), 'arrow-right'],
        ]"
        :rows="array_values(array_filter([
            $isRestaurant ? ['Add-ons (extra cheese, sides...)', route('seller.add-ons'), 'plus'] : null,
            [$isRestaurant ? 'Add many dishes' : 'Add many products', route('seller.products.add-many'), 'document-text'],
            [$isRestaurant ? 'Problems with orders' : 'Returns', route('seller.orders', ['tab' => 'returns']), 'arrow-path'],
        ]))" />
</x-layouts.seller>
