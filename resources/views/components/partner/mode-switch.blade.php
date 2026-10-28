@props(['current'])
@php
    // One account, several partner roles (owner 2026-10-27): a service partner can also deliver parcels or run a shop
    // (E-commerce Phase 1), and every other way round. Each role the account holds → one tap switches dashboards (same
    // sign-in). One role it does not hold yet → apply for it on the partner page, but only while that role is taking
    // sign-ups.
    $user = auth()->user();
    $modes = [
        'service' => ['Switch to partner mode', 'provider.dashboard', 'Offer services too'],
        'parcel' => ['Switch to rider mode', 'rider.dashboard', 'Deliver parcels too'],
        'commerce' => ['Switch to my shop', 'seller.dashboard', 'Open a shop too'],
    ];
    $links = [];
    $apply = null;
    foreach ($modes as $role => [$switchLabel, $route, $applyLabel]) {
        if ($role === $current || ! $user) {
            continue;
        }
        if ($user->holdsPartnerRole($role)) {
            $links[] = [$switchLabel, route($route)];
        } elseif ($apply === null && $user->canApplyAsPartner($role)
            && (collect(\App\Support\PartnerPage\PartnerPageData::roles())->firstWhere('code', $role)['hands_off'] ?? false)) {
            $apply = [$applyLabel, route('customer.partners', ['role' => $role]).'#formCard'];
        }
    }
    if ($apply) {
        $links[] = $apply;
    }
@endphp
@foreach ($links as [$label, $href])
    <a href="{{ $href }}" {{ $attributes->merge(['class' => 'font-medium text-blue-700 hover:underline']) }}>{{ $label }}</a>
@endforeach
