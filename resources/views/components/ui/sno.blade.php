@props(['rows' => null, 'loop'])

{{--
    1CF-ADMIN-SNO-001: the S.No cell. First <td> of every admin list row.
    Display-only (App\Support\SerialNumber) -- never stored, never replaces
    the ID / code columns. Pass the same paginator the table iterates and the
    row's $loop so numbering continues across pages: page 2 at 25/page
    starts at 26.
--}}
<td {{ $attributes->merge(['class' => 'px-4 py-2 text-gray-400 tabular-nums']) }}>{{ \App\Support\SerialNumber::at($rows, $loop->iteration) }}</td>
