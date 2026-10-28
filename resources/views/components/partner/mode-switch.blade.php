@props(['current'])
@php
    // One account, several partner roles (owner 2026-10-27): a service partner can also deliver parcels and the other
    // way round. Holding the other role → one tap switches dashboards (same sign-in). Not yet → apply for it on the
    // partner page, but only while that role is taking sign-ups.
    $user = auth()->user();
    $other = $current === 'service' ? 'parcel' : 'service';
    $holds = $other === 'parcel'
        ? (bool) $user?->fieldWorkerProfile?->hasCapability('parcel_rider')
        : (bool) $user?->providerProfile;
    $link = null;
    if ($holds) {
        $link = $other === 'parcel' ? ['Switch to rider mode', route('rider.dashboard')] : ['Switch to partner mode', route('provider.dashboard')];
    } elseif ($user?->canApplyAsPartner($other)
        && (collect(\App\Support\PartnerPage\PartnerPageData::roles())->firstWhere('code', $other)['hands_off'] ?? false)) {
        $link = [$other === 'parcel' ? 'Deliver parcels too' : 'Offer services too', route('customer.partners', ['role' => $other]).'#formCard'];
    }
@endphp
@if ($link)
    <a href="{{ $link[1] }}" {{ $attributes->merge(['class' => 'font-medium text-blue-700 hover:underline']) }}>{{ $link[0] }}</a>
@endif
