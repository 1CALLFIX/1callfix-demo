@props(['imgClass' => 'h-10 w-10'])
@php
    $platformName = \App\Models\Setting::get('branding.platform_name', '1CallFix');
    $logoUrl = app(\App\Services\BrandingAssetService::class)->url('logo_display_path');
@endphp
{{-- Admin-uploaded logo (Settings → Platform / Branding). The logo carries its
     own wordmark, so it stands alone. With nothing uploaded this renders the
     original initial-in-a-square + platform-name mark, unchanged. --}}
@if ($logoUrl)
    <img src="{{ $logoUrl }}" alt="{{ $platformName }}" class="{{ $imgClass }} shrink-0 object-contain">
@else
    <span aria-hidden="true"
          class="grid h-9 w-9 place-items-center rounded-xl bg-blue-600 text-sm font-bold text-white shadow-sm shadow-blue-600/30">
        {{ \Illuminate\Support\Str::of($platformName)->substr(0, 1)->upper() }}
    </span>
    <span class="text-lg font-bold tracking-tight text-slate-900">{{ $platformName }}</span>
@endif
