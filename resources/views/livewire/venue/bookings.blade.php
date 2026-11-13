<div class="space-y-5">
    <h1 class="text-2xl font-bold tracking-tight">Bookings</h1>

    @if ($notice) <div role="status" class="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800" data-testid="venue-bookings-notice">{{ $notice }}</div> @endif
    @if ($error) <div role="alert" class="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{{ $error }}</div> @endif

    @if ($waiting->isNotEmpty())
        <section class="space-y-3">
            <h2 class="text-sm font-semibold uppercase text-amber-700">Waiting for your answer</h2>
            @foreach ($waiting as $b)
                <article class="space-y-3 rounded-2xl border-2 border-amber-300 bg-white p-4" data-testid="venue-booking-waiting">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <p class="font-semibold">{{ $b->event_date->format('D, j M Y') }} · {{ $b->slot_name }}</p>
                            <p class="text-sm text-slate-600">{{ $b->customer_name }} · <a href="tel:{{ $b->customer_phone }}" class="underline">{{ $b->customer_phone }}</a></p>
                            <p class="text-sm text-slate-600">{{ collect([$b->occasion, $b->guests ? $b->guests.' guests' : null, $many ? $b->hall?->name : null])->filter()->implode(' · ') }}</p>
                            @if ($b->notes) <p class="mt-1 text-sm text-slate-500">"{{ $b->notes }}"</p> @endif
                        </div>
                        <div class="text-right text-sm">
                            <p class="font-semibold">₹{{ number_format((float) $b->hall_price) }}</p>
                            <p class="text-xs text-amber-700">Answer by {{ $b->accept_by?->copy()->setTimezone($tz)->format('g:i A') }}</p>
                        </div>
                    </div>
                    @if ($decliningId === $b->id)
                        <div class="space-y-2">
                            <input type="text" wire:model="reason" maxlength="300" placeholder="Reason the customer sees, e.g. Already booked that day" class="block min-h-11 w-full rounded-lg border border-slate-300 px-3 text-base">
                            @error('reason') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
                            <div class="flex gap-2">
                                <button type="button" wire:click="decline" class="min-h-11 flex-1 rounded-lg bg-red-600 px-3 text-sm font-semibold text-white">Decline and refund</button>
                                <button type="button" wire:click="$set('decliningId', null)" class="min-h-11 rounded-lg border border-slate-300 px-3 text-sm">Back</button>
                            </div>
                        </div>
                    @else
                        <div class="flex gap-2">
                            <button type="button" wire:click="accept({{ $b->id }})" class="min-h-11 flex-1 rounded-lg bg-emerald-600 px-3 text-sm font-semibold text-white" data-testid="venue-accept">Accept</button>
                            <button type="button" wire:click="startDecline({{ $b->id }})" class="min-h-11 flex-1 rounded-lg border border-red-300 px-3 text-sm font-semibold text-red-700">Decline</button>
                        </div>
                    @endif
                </article>
            @endforeach
        </section>
    @endif

    <div class="flex gap-2">
        @foreach (['upcoming' => 'Upcoming', 'past' => 'Past'] as $k => $label)
            <button type="button" wire:click="$set('tab', '{{ $k }}')" @class(['min-h-10 rounded-full border px-4 text-sm font-medium', 'border-blue-600 bg-blue-600 text-white' => $tab === $k, 'border-slate-300 bg-white text-slate-700' => $tab !== $k])>{{ $label }}</button>
        @endforeach
    </div>

    <ul class="space-y-2">
        @forelse ($list as $b)
            <li class="flex items-start justify-between gap-3 rounded-2xl border border-slate-200 bg-white p-4 text-sm" data-testid="venue-booking">
                <div>
                    <p class="font-semibold">{{ $b->event_date->format('D, j M Y') }} · {{ $b->slot_name }}</p>
                    <p class="text-slate-600">{{ $b->customer_name }} · <a href="tel:{{ $b->customer_phone }}" class="underline">{{ $b->customer_phone }}</a></p>
                    <p class="text-slate-500">{{ collect([$b->occasion, $b->guests ? $b->guests.' guests' : null, $many ? $b->hall?->name : null])->filter()->implode(' · ') }}</p>
                </div>
                <div class="text-right">
                    <p class="font-semibold">₹{{ number_format((float) $b->hall_price) }}</p>
                    <p class="text-xs text-slate-500">{{ $b->statusLabel() }}</p>
                </div>
            </li>
        @empty
            <li class="rounded-2xl border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-600">{{ $tab === 'past' ? 'No past bookings yet.' : 'No upcoming bookings yet. Keep your slots, prices and blocked dates up to date.' }}</li>
        @endforelse
    </ul>
</div>
