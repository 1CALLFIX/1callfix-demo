@props(['rows' => null, 'loop', 'class' => 'px-4 py-2'])

{{--
    1CF-ADMIN-SNO-001: the S.No cell. First <td> of every admin list row.
    Display-only (App\Support\SerialNumber) -- never stored, never replaces
    the ID / code columns. Pass the same paginator the table iterates and the
    row's $loop so numbering continues across pages: page 2 at 25/page
    starts at 26. `class` carries the table's own cell padding (compact
    sub-tables use py-1 / px-2).
--}}
<td {{ $attributes->merge(['class' => $class.' text-gray-400 tabular-nums']) }}>{{ \App\Support\SerialNumber::at($rows, $loop->iteration) }}</td>
