{{-- How long this partner's documents have waited (Needs review), else when they applied. Amber and red (Overdue)
     hours are Super Admin settings (Admin → Homepage & sign-up → Partner review). --}}
@if ($statusFilter === 'review' && $row->waiting_since)
    @php
        $hours = \App\Support\PartnerReview::hours($row->waiting_since);
        $level = \App\Support\PartnerReview::level($hours);
    @endphp
    <span @class(['font-medium', 'text-red-700' => $level === 'red', 'text-amber-700' => $level === 'amber'])>{{ $hours }} h</span>
    @if ($level === 'red')
        <span class="ml-1 inline-flex items-center rounded-full bg-red-600 px-2 py-0.5 text-xs font-semibold text-white motion-safe:animate-pulse">Overdue</span>
    @endif
@else
    {{ $row->created_at->diffForHumans() }}
@endif
