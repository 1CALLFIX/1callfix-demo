@props(['created' => 'Created', 'completed' => 'Completed', 'tat' => 'TAT', 'class' => 'px-4 py-2'])

{{-- Headers for <x-ui.tat-cells /> (or any Created | Completed | TAT trio). --}}
<th class="{{ $class }}">{{ $created }}</th>
<th class="{{ $class }}">{{ $completed }}</th>
<th class="{{ $class }}" title="Turnaround time: from created to completed (amber = still open, time so far)">{{ $tat }}</th>
