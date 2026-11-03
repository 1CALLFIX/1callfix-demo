@php
    $tz = app(\App\Services\TimezoneResolver::class);
    $fmt = fn ($n) => $currencySymbol.number_format((float) $n, 2);
@endphp
<div>
    <h1 class="text-xl font-bold tracking-tight">History</h1>
    <p class="mt-1 text-sm text-slate-600">Every finished job: parcels, shop and food deliveries, and return pickups.</p>

    @forelse ($jobs as $row)
        <div class="mt-3 rounded-2xl border border-slate-200 bg-white p-4 text-sm shadow-sm" data-testid="history-row">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <x-rider.kind :kind="$row['kind']" />
                        <span @class(['text-xs font-semibold', 'text-emerald-700' => $row['done'], 'text-slate-500' => ! $row['done']])>{{ $row['done'] ? ($row['kind'] === 'return' ? 'Returned' : 'Delivered') : 'Cancelled' }}</span>
                    </div>
                    <p class="mt-1 font-semibold">{{ $row['code'] }}</p>
                    <p class="truncate text-slate-600">{{ $row['where'] }}</p>
                    <p class="text-xs text-slate-500">{{ $row['at'] ? $tz->format($row['at'], $row['franchise'], 'j M Y, g:i A') : '' }}@if ($row['amount'] !== null) · {{ $row['cash'] ? 'Cash' : 'Prepaid' }}@endif</p>
                </div>
                <div class="shrink-0 text-right">
                    @if ($row['amount'] !== null)<p class="font-semibold tabular-nums">{{ $fmt($row['amount']) }}</p>@endif
                    @if ($row['earned'] !== null)
                        <p class="text-xs font-semibold text-emerald-700">You earn {{ $fmt($row['earned']) }}</p>
                    @endif
                </div>
            </div>
        </div>
    @empty
        <p class="mt-6 rounded-2xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-600">No finished jobs yet.</p>
    @endforelse

    <div class="mt-5">{{ $jobs->links() }}</div>
</div>
