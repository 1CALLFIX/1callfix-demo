@props(['imgClass' => 'h-10 w-10', 'nameClass' => ''])
@php
    $platformName = \App\Models\Setting::get('branding.platform_name', '1CallFix');
    $logoUrl = app(\App\Services\BrandingAssetService::class)->url('logo_display_path');
    $nameBesideLogo = \App\Services\BrandingAssetService::nameBesideLogo();
@endphp
{{-- Admin-uploaded logo (Settings → Platform / Branding). The logo carries its
     own wordmark, so at header size it usually stands alone — but the
     wordmark baked into a square badge is illegible at that size, so an
     admin can opt into a separate "Brand name beside logo" text (REF
     1CF-BRANDING-NAME-BESIDE-LOGO-001), shown only next to the logo image.
     With nothing uploaded this renders the original initial-in-a-square +
     platform-name mark instead, unchanged, so the name is never shown
     twice for the same mark. --}}
@if ($logoUrl)
    <img src="{{ $logoUrl }}" alt="{{ $platformName }}" class="{{ $imgClass }} shrink-0 object-contain">
    @if ($nameBesideLogo !== '')
        <span class="{{ $nameClass }} text-lg font-bold tracking-tight text-slate-900">{{ $nameBesideLogo }}</span>
    @endif
@else
    <span aria-hidden="true"
          class="grid h-9 w-9 place-items-center rounded-xl bg-blue-600 text-sm font-bold text-white shadow-sm shadow-blue-600/30">
        {{ \Illuminate\Support\Str::of($platformName)->substr(0, 1)->upper() }}
    </span>
    <span class="text-lg font-bold tracking-tight text-slate-900">{{ $platformName }}</span>
@endif
