@props(['badge'])

{{--
    One catalog badge — NEW, POPULAR, FEATURED, FLASH SALE, or any custom one
    an admin creates (Phase C).

    Renders the Badge engine's own display shape verbatim
    (Badge::toDisplayArray(): key, label, icon, text_color, bg_color,
    priority). Nothing about which badges apply, when they expire, or how
    they are ordered is decided here — that is BadgeService's job, and this
    component only paints what it is handed. In particular the NEW badge's
    expiry is not re-checked here: it is an `automatic` badge evaluated live
    against its own admin-editable `within_days` rule, so an expired one
    simply never arrives.

    Colours come from admin-editable columns, so they are validated as real
    CSS hex before being interpolated into a style attribute. An admin who
    pastes something else gets the neutral default rather than a chance to
    inject CSS into every customer's page.
--}}

@php
    $hex = static fn (?string $value, string $fallback): string =>
        is_string($value) && preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value)
            ? $value
            : $fallback;

    $bg = $hex($badge['bg_color'] ?? null, '#0f172a');
    $fg = $hex($badge['text_color'] ?? null, '#ffffff');
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] font-bold uppercase leading-none tracking-wide']) }}
      style="background-color: {{ $bg }}; color: {{ $fg }};">
    @if (! empty($badge['icon']))
        <x-icon :name="$badge['icon']" class="h-3 w-3" />
    @endif
    {{ $badge['label'] ?? '' }}
</span>
