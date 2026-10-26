{{-- How long this partner's documents have waited (Needs review), else when they applied. --}}
@if ($statusFilter === 'review' && $row->waiting_since)
    @php $hours = (int) \Illuminate\Support\Carbon::parse($row->waiting_since)->diffInHours(now()); @endphp
    <span @class(['font-medium', 'text-red-700' => $hours >= 48, 'text-amber-700' => $hours >= 24 && $hours < 48])>{{ $hours }} h</span>
@else
    {{ $row->created_at->diffForHumans() }}
@endif
