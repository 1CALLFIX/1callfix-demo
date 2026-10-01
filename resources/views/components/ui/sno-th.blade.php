@props(['class' => 'px-4 py-2'])
{{-- 1CF-ADMIN-SNO-001: the S.No header cell; pairs with <x-ui.sno>. --}}
<th {{ $attributes->merge(['class' => $class.' w-14 whitespace-nowrap']) }}>S.No</th>
