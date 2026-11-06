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
    // Owner rule 2026-11-06: deliveries live in the Rider app, services and shops in the Partner app. Inside one
    // of those two apps, a role that belongs to the other app opens that app (its Play Store page, which the app
    // turns into "open it" when it is installed) instead of loading the other app's screens here. Same sign-in.
    $appFor = ['service' => 'partner', 'commerce' => 'partner', 'parcel' => 'rider'];
    $appName = ['partner' => 'Open the Partner app', 'rider' => 'Open the Rider app'];
    $flavor = \App\Support\NativeApp::flavor();
    $links = [];
    $apply = null;
    foreach ($modes as $role => [$switchLabel, $route, $applyLabel]) {
        if ($role === $current || ! $user) {
            continue;
        }
        if ($user->holdsPartnerRole($role)) {
            $target = $appFor[$role];
            $links[] = in_array($flavor, ['partner', 'rider'], true) && $flavor !== $target
                ? [$appName[$target], 'https://play.google.com/store/apps/details?id='.\App\Support\NativeApp::PACKAGES[$target]]
                : [$switchLabel, route($route)];
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
