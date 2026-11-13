<div class="space-y-5">
    <x-partner.join-banner class="mb-4" />
    <div>
        <h1 class="text-2xl font-bold tracking-tight">{{ $organiser->name }}</h1>
        <p class="mt-1 text-sm text-slate-600">Event organiser · {{ $organiser->address_line }}</p>
    </div>

    @if ($organiser->approval_status === 'pending')
        <div role="status" class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" data-testid="organiser-pending">
            <p class="font-semibold">Waiting for approval</p>
            <p class="mt-1">Our team checks your documents. Meanwhile, create your event and send it for review, so it can go on sale as soon as you are approved.</p>
        </div>
    @elseif ($organiser->approval_status === 'rejected')
        <div role="alert" class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-800" data-testid="organiser-rejected">
            <p class="font-semibold">Not approved yet</p>
            @if ($organiser->rejection_reason) <p class="mt-1">{{ $organiser->rejection_reason }}</p> @endif
            <p class="mt-1">Fix this and call us; we will check again.</p>
        </div>
    @elseif (! $moduleLive)
        <div class="rounded-2xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900" data-testid="organiser-approved-soon">
            <p class="font-semibold">Approved</p>
            <p class="mt-1">Ticket sales open in your city soon. Create your events now and send them for review.</p>
        </div>
    @else
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900" data-testid="organiser-live">
            <p class="font-semibold">Live</p>
            <p class="mt-1">Approved events are on sale to customers.</p>
        </div>
    @endif

    <div class="grid grid-cols-3 gap-3">
        @foreach (['approved' => 'Approved', 'in_review' => 'In review', 'draft' => 'Drafts'] as $s => $label)
            <a href="{{ route('organiser.events') }}" wire:navigate class="rounded-2xl border border-slate-200 bg-white p-4">
                <p class="text-xs font-semibold uppercase text-slate-500">{{ $label }}</p>
                <p class="mt-1 text-2xl font-bold">{{ (int) ($counts[$s] ?? 0) }}</p>
            </a>
        @endforeach
    </div>

    @if ($next)
        <a href="{{ route('organiser.events.edit', $next->id) }}" wire:navigate class="block rounded-2xl border border-slate-200 bg-white p-4">
            <p class="text-xs font-semibold uppercase text-slate-500">Next event</p>
            <p class="mt-1 font-semibold">{{ $next->title }}</p>
            <p class="text-sm text-slate-600">{{ $next->starts_at->timezone($tz)->format('D, j M Y · g:i A') }} · {{ $next->statusLabel() }}</p>
        </a>
    @endif

    <div class="rounded-2xl border border-slate-200 bg-white p-4">
        <h2 class="text-sm font-semibold uppercase text-slate-500">{{ $ready ? 'All set' : 'Before you go live' }}</h2>
        <ul class="mt-3 space-y-2">
            @foreach ($checklist as $item)
                <li>
                    <a href="{{ route($item['route']) }}" wire:navigate class="flex items-center gap-3 text-sm">
                        <span @class(['grid h-6 w-6 shrink-0 place-items-center rounded-full text-xs font-bold',
                            'bg-emerald-600 text-white' => $item['done'], 'border border-slate-300 text-transparent' => ! $item['done']])>&check;</span>
                        <span @class(['text-slate-500 line-through' => $item['done']])>{{ $item['label'] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </div>

    <a href="{{ route('organiser.events.create') }}" wire:navigate class="flex min-h-12 items-center justify-center rounded-xl bg-blue-600 px-4 font-semibold text-white hover:bg-blue-700" data-testid="new-event">+ Create an event</a>
</div>
