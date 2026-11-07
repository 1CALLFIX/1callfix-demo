@props(['use'])
{{-- Best picture size for this upload (owner 2026-11-07), from App\Support\ImageSizes. Customer screens read their line
     from Website text ("upload.<use>", en + te) like every other customer word. --}}
@php
    $text = array_key_exists('upload.'.$use, \App\Support\SiteText::TEXTS)
        ? \App\Support\SiteText::get('upload.'.$use)
        : \App\Support\ImageSizes::line($use);
@endphp
<p {{ $attributes->has('class') ? $attributes : $attributes->merge(['class' => 'mt-1 text-xs text-gray-500']) }} data-image-size="{{ $use }}">{{ $text }}</p>
