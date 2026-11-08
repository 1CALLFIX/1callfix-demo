{{-- "Get it on Google Play" button in the store's own black-badge style (owner 2026-11-08). $size: md | sm. --}}
@props(['size' => 'md'])
@php $t = fn (string $key) => \App\Support\SiteText::get($key); @endphp
<a href="{{ \App\Support\AppLinks::android() }}" target="_blank" rel="noopener" data-testid="play-badge"
   {{ $attributes->class([
       'group inline-flex items-center gap-2.5 rounded-xl bg-slate-950 text-white ring-1 ring-white/15 transition hover:-translate-y-0.5 hover:bg-black hover:shadow-lg hover:shadow-black/25 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-400',
       'h-14 px-4' => $size === 'md',
       'h-11 px-3' => $size === 'sm',
   ]) }}>
    <svg @class(['shrink-0', 'h-7 w-7' => $size === 'md', 'h-6 w-6' => $size === 'sm']) viewBox="0 0 24 26" aria-hidden="true">
        <path d="M1.2.6 13.4 12.8 1.2 25a1.6 1.6 0 0 1-.7-1.4V2A1.6 1.6 0 0 1 1.2.6Z" fill="#00d7fe"/>
        <path d="m17.5 8.7-4.1 4.1-12.2-12.2c.4-.3 1-.3 1.6 0l14.7 8.1Z" fill="#00f076"/>
        <path d="m17.5 16.9-14.7 8.2c-.6.3-1.2.2-1.6-.1l12.2-12.2 4.1 4.1Z" fill="#ff3a44"/>
        <path d="m22.6 14.2-5.1 2.7-4.1-4.1 4.1-4.1 5.1 2.8c1.1.6 1.1 2.1 0 2.7Z" fill="#ffd500"/>
    </svg>
    <span class="flex flex-col text-left leading-none">
        <span @class(['font-medium uppercase tracking-wide text-white/80', 'text-[10px]' => $size === 'md', 'text-[9px]' => $size === 'sm'])>{{ $t('get_app.play_small') }}</span>
        <span @class(['mt-1 font-semibold tracking-tight', 'text-lg' => $size === 'md', 'text-base' => $size === 'sm'])>{{ $t('get_app.play_big') }}</span>
    </span>
</a>
