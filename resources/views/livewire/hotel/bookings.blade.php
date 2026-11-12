<?php
    $tz = app(\App\Services\TimezoneResolver::class);
    $btn = 'inline-flex min-h-11 items-center justify-center rounded-xl px-4 text-sm font-semibold';
?>
<div class="space-y-4" @if ($active === 'accept' || ($counts['accept'] ?? 0) > 0) wire:poll.15s @endif>
    <h1 class="text-2xl font-bold tracking-tight">Bookings</h1>

    <div class="flex gap-2 overflow-x-auto" role="tablist">
        @foreach ($tabs as $code => $label)
            <button type="button" role="tab" wire:click="$set('tab', '{{ $code }}')" aria-selected="{{ $active === $code ? 'true' : 'false' }}"
                    @class(['inline-flex min-h-10 shrink-0 items-center gap-1 rounded-full border px-3 text-sm font-semibold',
                            'border-blue-600 bg-blue-600 text-white' => $active === $code, 'border-slate-300 bg-white text-slate-700' => $active !== $code])>
                {{ $label }}
                @if (($counts[$code] ?? 0) > 0)
                    <span @class(['rounded-full px-1.5 text-xs', 'bg-white text-blue-700' => $active === $code, 'bg-rose-600 text-white' => $active !== $code])>{{ $counts[$code] }}</span>
                @endif
            </button>
        @endforeach
    </div>

    @if ($notice) <div role="status" class="rounded-lg bg-emerald-50 px-3 py-2.5 text-sm text-emerald-800">{{ $notice }}</div> @endif
    @if ($error) <div role="alert" class="rounded-lg bg-red-50 px-3 py-2.5 text-sm text-red-800">{{ $error }}</div> @endif

    @if ($active === 'accept' && $rows->isNotEmpty())
        <div>
            <label for="decline-reason" class="block text-xs font-medium text-slate-600">Reason, used by "Decline" (the guest sees it)</label>
            <input id="decline-reason" type="text" wire:model="declineReason" maxlength="200" placeholder="Fully booked on these dates"
                   class="mt-1 block min-h-11 w-full rounded-lg border border-slate-300 px-3 text-base">
            @error('declineReason') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>
    @endif

    <ul class="space-y-3" data-testid="hotel-bookings">
        @forelse ($rows as $r)
            @php $guest = $r->guests->firstWhere('is_primary', true) ?? $r->guests->first(); @endphp
            <li class="rounded-2xl border border-slate-200 bg-white p-4" data-testid="hotel-booking-{{ $r->id }}">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-base font-semibold">{{ $guest?->name ?? 'Guest' }} <span class="text-xs font-normal text-slate-500">· {{ $r->code }}</span></p>
                        <p class="text-sm text-slate-700">{{ $r->check_in_date->format('D j M') }} → {{ $r->check_out_date->format('D j M') }} · {{ $r->number_of_nights }} {{ \Illuminate\Support\Str::plural('night', $r->number_of_nights) }} · {{ $r->number_of_adults }} {{ \Illuminate\Support\Str::plural('adult', $r->number_of_adults) }}</p>
                        @foreach ($r->rooms as $line)
                            <p class="text-sm text-slate-600">{{ $line->room_count }} × {{ $line->roomType?->name }} · {{ \App\Livewire\Hotel\Rooms::MEAL_PLANS[$line->ratePlan?->meal_plan] ?? 'Room only' }}</p>
                        @endforeach
                        @if ($guest?->phone) <a href="tel:{{ $guest->phone }}" class="text-sm font-medium text-blue-600 underline">{{ $guest->phone }}</a> @endif
                        @if ($r->special_requests) <p class="mt-1 rounded-lg bg-amber-50 px-2 py-1 text-sm text-amber-900">Request: {{ $r->special_requests }}</p> @endif
                        @if ($r->guest_gst_number) <p class="mt-1 text-xs text-slate-500">GST invoice: {{ $r->guest_gst_number }} · {{ $r->guest_business_name }}</p> @endif
                    </div>
                    <p class="shrink-0 text-right text-sm"><span class="font-bold">₹{{ number_format((float) $r->price_quoted) }}</span><span class="block text-xs text-slate-500">room price</span></p>
                </div>

                @if ($r->status === 'pending')
                    @if ($r->accept_by) <p class="mt-2 text-sm font-semibold text-red-600">Accept before {{ $tz->format($r->accept_by, $r->franchise, 'g:i A') }}, or it is cancelled.</p> @endif
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button" wire:click="accept({{ $r->id }})" wire:loading.attr="disabled" class="{{ $btn }} bg-emerald-600 text-white hover:bg-emerald-700">Accept</button>
                        <button type="button" wire:click="decline({{ $r->id }})" wire:confirm="Decline this booking? The guest gets a full refund." class="{{ $btn }} border border-slate-300 text-slate-700">Decline</button>
                    </div>
                @elseif ($r->status === 'confirmed')
                    <form wire:submit="checkIn({{ $r->id }})" class="mt-3 flex flex-wrap items-center gap-2">
                        <input type="text" inputmode="numeric" maxlength="6" wire:model="codes.{{ $r->id }}" placeholder="Guest's code" aria-label="Guest's 4-digit check-in code"
                               class="min-h-11 w-32 rounded-lg border border-slate-300 px-3 text-base tracking-widest">
                        <button type="submit" class="{{ $btn }} bg-blue-600 text-white hover:bg-blue-700" @disabled($r->check_in_date->gt($today))>Check in</button>
                        @if ($r->check_in_date->lte($today))
                            <button type="button" wire:click="noShow({{ $r->id }})" wire:confirm="Mark that the guest did not arrive? The no-show charge is kept and the rest refunded." class="{{ $btn }} border border-slate-300 text-slate-700">Did not arrive</button>
                        @else
                            <span class="text-xs text-slate-500">Check-in opens {{ $r->check_in_date->format('j M') }}.</span>
                        @endif
                    </form>
                    <p class="mt-1 text-xs text-slate-500">Check the guest's photo ID at the desk, then type the 4-digit code from their booking.</p>
                @elseif ($r->status === 'checked_in')
                    <button type="button" wire:click="checkOut({{ $r->id }})" wire:confirm="Check out this guest?" class="mt-3 {{ $btn }} bg-blue-600 text-white hover:bg-blue-700">Check out</button>
                @else
                    <p class="mt-2 text-sm text-slate-600">
                        {{ $r->status === 'cancelled' ? ($r->no_show ? 'Did not arrive' : 'Cancelled').($r->cancellation_note ? ': '.$r->cancellation_note : '') : ucfirst(str_replace('_', ' ', $r->status)) }}
                        @if ((float) $r->cancellation_fee > 0) · charge kept ₹{{ number_format((float) $r->cancellation_fee, 2) }} @endif
                    </p>
                @endif
            </li>
        @empty
            <li class="rounded-2xl border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-600">Nothing here.</li>
        @endforelse
    </ul>
</div>
