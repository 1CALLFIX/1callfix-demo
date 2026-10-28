@props([
    'record',
    'done' => null,            // the record's own success-time column: completed_at, delivered_at, ...
    'from' => 'created_at',
    'franchise' => null,
    'class' => 'px-4 py-2',
])

{{--
    Three cells — Created | Completed | TAT — for any status-driven admin list (bookings, parcel orders, rides,
    marketplace orders, reservations). Pair with <x-ui.tat-heads />. Close time and outcome come from
    App\Support\Turnaround::close(); eager-load statusHistory with Turnaround::historyScope() to keep it to one query.
--}}

@php($close = \App\Support\Turnaround::close($record, $done))

<td class="{{ $class }}"><x-ui.datetime :at="$record->{$from}" :franchise="$franchise" /></td>
<td class="{{ $class }}"><x-ui.closed-at :at="$close['at']" :outcome="$close['outcome']" :open="$close['open']" :franchise="$franchise" /></td>
<td class="{{ $class }}"><x-ui.tat :from="$record->{$from}" :to="$close['at']" :open="$close['open']" :outcome="$close['outcome']" /></td>
