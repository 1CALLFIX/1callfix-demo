@props(['item', 'title', 'image' => null, 'hash' => null, 'small' => false])
{{-- ♡ Like + Share as two round buttons, placed by the page on a photo's top-right corner (owner 2026-11-07). --}}
<div {{ $attributes->merge(['class' => 'flex items-center gap-2']) }} data-item-actions>
    <x-customer.save-button :item="$item" :title="$title" :image="$image" :hash="$hash" :small="$small" />
    <x-customer.share-button round :title="$title" :hash="$hash" :small="$small" />
</div>
