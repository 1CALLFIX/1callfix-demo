<div class="space-y-5">
    <x-partner.join-banner class="mb-4" />
    <div>
        <h1 class="text-2xl font-bold tracking-tight">{{ $homes->isEmpty() && $halls->count() === 1 ? $halls->first()->name : 'Your rentals' }}</h1>
        <p class="mt-1 text-sm text-slate-600">{{ collect([$halls->isNotEmpty() ? 'Function halls' : null, $homes->isNotEmpty() ? 'Property for rent' : null])->filter()->implode(' · ') }} · {{ $partner->user?->name }}</p>
    </div>

    @if ($halls->contains('status', 'approved') && ! $moduleLive)
        <div class="rounded-2xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900" data-testid="halls-opening-soon">
            <p class="font-semibold">Approved</p>
            <p class="mt-1">Online hall booking opens in your city soon. Keep your slots, prices and blocked dates up to date.</p>
        </div>
    @endif

    @foreach ($halls as $hall)
        <?php
            $checklist = \App\Livewire\Venue\Dashboard::checklist($hall);
            $tone = ['approved' => 'border-emerald-200 bg-emerald-50 text-emerald-900', 'in_review' => 'border-amber-200 bg-amber-50 text-amber-900', 'rejected' => 'border-red-200 bg-red-50 text-red-800'][$hall->status] ?? 'border-slate-200 bg-white text-slate-800';
        ?>
        <section class="space-y-3 rounded-2xl border border-slate-200 bg-white p-4" data-testid="venue-hall">
            <div class="flex gap-3">
                <div class="h-16 w-24 shrink-0 overflow-hidden rounded-lg bg-slate-100">
                    @if ($hall->coverUrl()) <img src="{{ $hall->coverUrl() }}" alt="" class="h-full w-full object-cover" loading="lazy"> @endif
                </div>
                <div class="min-w-0 flex-1">
                    <p class="truncate font-semibold">{{ $hall->name }}</p>
                    <p class="truncate text-sm text-slate-600">{{ $hall->hall_type ?: 'Function hall' }} @if ($hall->seating_capacity) · {{ $hall->seating_capacity }} seats @endif</p>
                    <p class="mt-1 flex flex-wrap gap-2 text-xs">
                        <span class="rounded-full border px-2 py-0.5 font-medium {{ $tone }}">{{ $hall->statusLabel() }}</span>
                        @if ($hall->status === 'approved')
                            <span @class(['rounded-full px-2 py-0.5 font-medium', 'bg-emerald-100 text-emerald-800' => $hall->is_active, 'bg-slate-100 text-slate-600' => ! $hall->is_active])>{{ $hall->is_active ? 'On' : 'Switched off' }}</span>
                        @endif
                        @if ($hall->pending_changes) <span class="rounded-full bg-amber-100 px-2 py-0.5 font-medium text-amber-800">Change waiting</span> @endif
                    </p>
                </div>
            </div>

            @if ($hall->status === 'rejected' && $hall->rejection_reason)
                <p role="alert" class="rounded-xl bg-red-50 px-3 py-2 text-sm text-red-800">{{ $hall->rejection_reason }}</p>
            @elseif ($hall->status === 'in_review')
                <p role="status" class="rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-900">We are checking your hall. You will see the result here, usually within a day.</p>
            @endif

            @if ($hall->status !== 'approved')
                <ul class="space-y-2" aria-label="Before you go live">
                    @foreach ($checklist as $item)
                        <li class="flex items-center gap-3 text-sm">
                            <span @class(['grid h-6 w-6 shrink-0 place-items-center rounded-full text-xs font-bold',
                                'bg-emerald-600 text-white' => $item['done'], 'border border-slate-300 text-transparent' => ! $item['done']])>&check;</span>
                            <span @class(['text-slate-500 line-through' => $item['done']])>{{ $item['label'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif

            <a href="{{ route('venue.halls.edit', $hall->id) }}" wire:navigate class="flex min-h-11 items-center justify-center rounded-xl {{ $hall->isEditable() ? 'bg-blue-600 text-white hover:bg-blue-700' : 'border border-slate-300 text-slate-800 hover:bg-slate-50' }} px-4 text-sm font-semibold">
                {{ $hall->isEditable() ? 'Finish your hall and send for review' : 'Open hall, slots and prices' }}
            </a>
        </section>
    @endforeach

    @if ($halls->isNotEmpty())
        <a href="{{ route('venue.halls.create') }}" wire:navigate class="flex min-h-11 items-center justify-center rounded-xl border border-dashed border-slate-300 px-4 text-sm font-medium text-slate-700 hover:border-blue-400" data-testid="add-hall">+ Add another hall</a>
    @endif

    {{-- Property for rent (2026-11-14) --}}
    @if ($homes->contains('status', 'approved') && ! $homesLive)
        <div class="rounded-2xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900" data-testid="homes-opening-soon">
            <p class="font-semibold">Approved</p>
            <p class="mt-1">Property for rent opens to customers in your city soon. Keep your rent and photos up to date.</p>
        </div>
    @endif
    @foreach ($homes as $home)
        <?php
            $checklist = \App\Livewire\Venue\Dashboard::homeChecklist($home);
            $tone = ['approved' => 'border-emerald-200 bg-emerald-50 text-emerald-900', 'in_review' => 'border-amber-200 bg-amber-50 text-amber-900', 'rejected' => 'border-red-200 bg-red-50 text-red-800'][$home->status] ?? 'border-slate-200 bg-white text-slate-800';
        ?>
        <section class="space-y-3 rounded-2xl border border-slate-200 bg-white p-4" data-testid="venue-home">
            <div class="flex gap-3">
                <div class="h-16 w-24 shrink-0 overflow-hidden rounded-lg bg-slate-100">
                    @if ($home->coverUrl()) <img src="{{ $home->coverUrl() }}" alt="" class="h-full w-full object-cover" loading="lazy"> @endif
                </div>
                <div class="min-w-0 flex-1">
                    <p class="truncate font-semibold">🏠 {{ $home->title }}</p>
                    <p class="truncate text-sm text-slate-600">@if ($home->rent) ₹{{ number_format((float) $home->rent) }} / month @else Rent not added yet @endif</p>
                    <p class="mt-1 flex flex-wrap gap-2 text-xs">
                        <span class="rounded-full border px-2 py-0.5 font-medium {{ $tone }}">{{ $home->statusLabel() }}</span>
                        @if ($home->status === 'approved')
                            @if ($home->rented_at) <span class="rounded-full bg-slate-100 px-2 py-0.5 font-medium text-slate-600">Rented</span>
                            @elseif ($home->expires_at && $home->expires_at->isPast()) <span class="rounded-full bg-amber-100 px-2 py-0.5 font-medium text-amber-800">Hidden: tap Still available</span>
                            @else <span class="rounded-full bg-emerald-100 px-2 py-0.5 font-medium text-emerald-800">Customers see it</span> @endif
                        @endif
                        @if ($home->pending_changes) <span class="rounded-full bg-amber-100 px-2 py-0.5 font-medium text-amber-800">Change waiting</span> @endif
                    </p>
                </div>
            </div>
            @if ($home->status === 'rejected' && $home->rejection_reason)
                <p role="alert" class="rounded-xl bg-red-50 px-3 py-2 text-sm text-red-800">{{ $home->rejection_reason }}</p>
            @elseif ($home->status === 'in_review')
                <p role="status" class="rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-900">We are checking your property. You will see the result here, usually within a day.</p>
            @endif
            @if ($home->status !== 'approved')
                <ul class="space-y-2" aria-label="Before you go live">
                    @foreach ($checklist as $item)
                        <li class="flex items-center gap-3 text-sm">
                            <span @class(['grid h-6 w-6 shrink-0 place-items-center rounded-full text-xs font-bold',
                                'bg-emerald-600 text-white' => $item['done'], 'border border-slate-300 text-transparent' => ! $item['done']])>&check;</span>
                            <span @class(['text-slate-500 line-through' => $item['done']])>{{ $item['label'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
            <a href="{{ route('venue.homes.edit', $home->id) }}" wire:navigate class="flex min-h-11 items-center justify-center rounded-xl {{ $home->isEditable() ? 'bg-blue-600 text-white hover:bg-blue-700' : 'border border-slate-300 text-slate-800 hover:bg-slate-50' }} px-4 text-sm font-semibold">
                {{ $home->isEditable() ? 'Finish your property and send for review' : 'Open property, rent and photos' }}
            </a>
        </section>
    @endforeach
    @if ($homes->isNotEmpty())
        <a href="{{ route('venue.homes.create') }}" wire:navigate class="flex min-h-11 items-center justify-center rounded-xl border border-dashed border-slate-300 px-4 text-sm font-medium text-slate-700 hover:border-blue-400" data-testid="add-home">+ Add another property for rent</a>
    @endif
</div>
