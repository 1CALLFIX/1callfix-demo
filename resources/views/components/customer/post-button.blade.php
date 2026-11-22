@props(['item', 'title', 'image' => null, 'hash' => null, 'small' => false])
@php
    // 📣 Make a social post (owner 2026-11-22): only for marketing staff (marketing.posts.manage), beside ♡ and Share on
    // every item photo. Opens Admin → Social posts with this item's picture, words and link filled in. Signed-in pages
    // are never page-cached, so customers never get this button.
    $user = auth()->user();
    $canPost = $user && $user->hasPermissionAnywhere('marketing.posts.manage');
    if ($canPost) {
        $path = '/'.ltrim(\Livewire\Livewire::isLivewireRequest() ? (string) \Livewire\Livewire::originalPath() : request()->path(), '/');
        $ticket = \App\Support\SavedItems::ticket($item, $title, $path.($hash ? '#'.$hash : ''), $image);
    }
    $box = $small ? 'h-8 w-8 text-sm' : 'h-10 w-10 text-base';
@endphp
@if ($canPost)
    <a href="{{ route('admin.marketing.posts', ['item' => $ticket]) }}" title="Make a social post" aria-label="Make a social post about {{ $title }}"
       {{ $attributes->merge(['class' => "inline-flex $box shrink-0 items-center justify-center rounded-full bg-white/95 shadow-md ring-1 ring-black/5 backdrop-blur transition hover:scale-105"]) }}
       data-post-button>📣</a>
@endif
