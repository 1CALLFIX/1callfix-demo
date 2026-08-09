@props(['current'])

{{-- The catalog is three sibling screens (Categories / SubCategories /
     Services) but the icon rail only has room for one "Services" entry, so
     they link to each other here. `current` is the active screen's key. --}}
@php
    $tabs = [
        'categories' => ['label' => 'Categories', 'route' => 'admin.categories.index'],
        'subcategories' => ['label' => 'SubCategories', 'route' => 'admin.subcategories.index'],
        'services' => ['label' => 'Services', 'route' => 'admin.services.index'],
    ];
@endphp

<div class="flex items-center gap-1 border-b mb-4">
    @foreach ($tabs as $key => $tab)
        <a href="{{ route($tab['route']) }}"
           @class([
               'px-4 py-2 text-sm border-b-2 -mb-px',
               'border-slate-900 text-slate-900 font-medium' => $current === $key,
               'border-transparent text-gray-500 hover:text-gray-800' => $current !== $key,
           ])>{{ $tab['label'] }}</a>
    @endforeach
</div>
