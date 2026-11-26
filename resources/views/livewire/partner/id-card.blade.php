{{-- Owner 2026-11-26: My ID card (App\Livewire\Partner\IdCardScreen). Plain steps until the card is ready, then the
     card itself (the same design as the PDF and the verify page: partner.id-card.card) and one big print button. --}}
@php
    $fontSize = 'min(10.8px, calc((100vw - 2.5rem) / 31.5))';
    $step = fn (bool $done, int $n) => '<span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-bold '.($done ? 'bg-emerald-600 text-white' : 'bg-blue-600 text-white').'">'.($done ? '✓' : $n).'</span>';
    $photoOk = in_array($state, ['ready'], true);
    $photoIn = in_array($state, ['ready', 'photo_pending'], true);
@endphp
<div class="space-y-4">
    <style>[x-cloak]{display:none!important}</style>
    <div>
        <h1 class="text-2xl font-bold tracking-tight">My ID card</h1>
        <p class="text-sm text-slate-600">Show it to customers at the door, or print it and wear it on every job.</p>
    </div>

    @if ($notice)
        <div role="status" class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ $notice }}</div>
    @endif

    @if ($state === 'not_field')
        <div class="rounded-2xl border border-slate-200 bg-white p-5 text-sm text-slate-700">
            ID cards are for partners who go to customers (service partners, riders and drivers).
        </div>
    @elseif ($state !== 'ready')
        {{-- Numbered steps: documents approved → photo → card. --}}
        <ol class="space-y-3">
            <li class="flex gap-3 rounded-2xl border border-slate-200 bg-white p-4">
                {!! $step($state !== 'not_approved', 1) !!}
                <div class="min-w-0">
                    <p class="font-semibold">Documents approved</p>
                    @if ($state === 'not_approved')
                        <p class="text-sm text-slate-600">Your card is made after your documents are approved.</p>
                        <a href="{{ route('partner.join') }}" wire:navigate class="mt-2 inline-flex min-h-11 items-center rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white">See my documents</a>
                    @else
                        <p class="text-sm text-slate-600">Done.</p>
                    @endif
                </div>
            </li>
            <li class="flex gap-3 rounded-2xl border border-slate-200 bg-white p-4" data-testid="id-card-photo-step">
                {!! $step($photoOk, 2) !!}
                <div class="min-w-0">
                    <p class="font-semibold">📸 Your photo</p>
                    @if ($state === 'photo_pending')
                        <span class="mt-1 inline-block rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">Being checked</span>
                        <p class="mt-1 text-sm text-slate-600">We check your photo and make your card. Usually within a day.</p>
                    @else
                        @if ($state === 'photo_rejected')
                            <span class="mt-1 inline-block rounded-full bg-rose-100 px-2 py-0.5 text-xs font-semibold text-rose-800">Please send a new photo</span>
                            @if ($photoDoc?->rejection_reason) <p class="mt-1 text-sm text-rose-800">{{ $photoDoc->rejection_reason }}</p> @endif
                        @endif
                        <p class="mt-1 text-sm text-slate-600">A clear photo of your face, looking at the camera, on a plain wall. No cap or dark glasses.</p>
                        @if ($state !== 'not_approved')
                            <a href="{{ $joinUrl }}" class="mt-2 inline-flex min-h-11 items-center rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white">Add my photo</a>
                        @endif
                    @endif
                </div>
            </li>
            <li class="flex gap-3 rounded-2xl border border-slate-200 bg-white p-4">
                {!! $step(false, 3) !!}
                <div class="min-w-0">
                    <p class="font-semibold">🩸 Blood group and emergency numbers</p>
                    <p class="text-sm text-slate-600">Printed on the back of your card. You add them here after your photo is approved.</p>
                </div>
            </li>
        </ol>
    @elseif ($status === 'not_active')
        <div class="rounded-2xl border border-rose-200 bg-rose-50 p-5 text-sm text-rose-800" data-testid="id-card-not-active">
            Your account is not active right now, so your ID card does not work. Please call your 1CallFix office.
        </div>
    @elseif ($needsDetails)
        {{-- Owner 2026-11-26: the back of the card shows Blood Group and Emergency Contact No's. Tap, do not type
             (except the numbers). Printed on the card only, never on the public check page. --}}
        <form wire:submit="saveDetails" class="space-y-4" data-testid="id-card-details">
            @if ($firstTime)
                <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
                    <p class="font-semibold">✓ Your photo is approved.</p>
                    <p>One last step: what goes on the back of your card.</p>
                </div>
            @endif

            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <p class="font-semibold">🩸 Your blood group</p>
                <div class="mt-3 flex flex-wrap gap-2" role="radiogroup" aria-label="Blood group">
                    @foreach (\App\Services\Partners\IdCardService::BLOOD_GROUPS as $key => $label)
                        <button type="button" role="radio" aria-checked="{{ $bloodGroup === $key ? 'true' : 'false' }}" wire:click="$set('bloodGroup', '{{ $key }}')"
                                @class(['inline-flex min-h-11 items-center rounded-full border px-4 text-sm font-semibold',
                                        'border-rose-600 bg-rose-600 text-white' => $bloodGroup === $key,
                                        'border-slate-300 bg-white text-slate-700' => $bloodGroup !== $key])>{{ $label }}</button>
                    @endforeach
                </div>
                @error('bloodGroup') <p class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>

            @foreach ([0, 1] as $i)
                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                    <p class="font-semibold">📞 Emergency number {{ $i + 1 }} @if ($i === 1)<span class="font-normal text-slate-500">(optional)</span>@endif</p>
                    <p class="text-sm text-slate-600">{{ $i === 0 ? 'A family member we can call if something happens to you on a job.' : 'Another family member or a friend.' }}</p>
                    <input type="tel" inputmode="numeric" autocomplete="off" maxlength="14" placeholder="10-digit mobile number"
                           wire:model="contacts.{{ $i }}.phone" aria-label="Emergency number {{ $i + 1 }}"
                           class="mt-3 block min-h-11 w-full rounded-xl border border-slate-300 px-3 text-base">
                    @error('contacts.'.$i.'.phone') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    <p class="mt-3 text-sm text-slate-600">Who is it?</p>
                    <div class="mt-2 flex flex-wrap gap-2" role="radiogroup" aria-label="Who is emergency number {{ $i + 1 }}">
                        @foreach (\App\Services\Partners\IdCardService::RELATIONS as $who)
                            <button type="button" role="radio" aria-checked="{{ ($contacts[$i]['relation'] ?? '') === $who ? 'true' : 'false' }}" wire:click="$set('contacts.{{ $i }}.relation', '{{ $who }}')"
                                    @class(['inline-flex min-h-11 items-center rounded-full border px-4 text-sm font-semibold',
                                            'border-blue-600 bg-blue-600 text-white' => ($contacts[$i]['relation'] ?? '') === $who,
                                            'border-slate-300 bg-white text-slate-700' => ($contacts[$i]['relation'] ?? '') !== $who])>{{ $who }}</button>
                        @endforeach
                    </div>
                    @error('contacts.'.$i.'.relation') <p class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
            @endforeach

            <p class="text-xs text-slate-500">These are printed on the back of your card only. The QR check page never shows them.</p>
            <button type="submit" class="flex min-h-12 w-full items-center justify-center rounded-xl bg-blue-600 px-4 font-semibold text-white" wire:loading.attr="disabled">
                Save and show my card
            </button>
        </form>
    @else
        @if ($status === 'expired')
            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" data-testid="id-card-expired">
                <p class="font-semibold">Your card expired on {{ $expiredOn }}.</p>
                <p class="mt-1">Get your new card for this year and print it.</p>
                <button type="button" wire:click="newCard" class="mt-3 inline-flex min-h-11 items-center rounded-xl bg-amber-500 px-4 font-semibold text-white">Get my new card</button>
            </div>
        @else
            <div class="space-y-3" data-testid="id-card">
                <div>@include('partner.id-card.card', ['c' => $c, 'side' => 'front', 'fontSize' => $fontSize])</div>
                <div>@include('partner.id-card.card', ['c' => $c, 'side' => 'back', 'fontSize' => $fontSize])</div>
            </div>

            <a href="{{ $printUrl }}" target="_blank" rel="noopener"
               class="flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 font-semibold text-white">
                <span aria-hidden="true">🖨️</span> Download to print
            </a>
            <p class="text-center text-xs text-slate-500">Print at actual size, cut, laminate. Or ask any print shop for a plastic card.</p>

            <div x-data="{ ask: false }" class="rounded-2xl border border-slate-200 bg-white p-4 text-sm">
                <div x-show="! ask" class="flex flex-wrap justify-between gap-2">
                    <button type="button" wire:click="$set('editing', true)" class="min-h-11 font-semibold text-blue-700">Change blood group or numbers</button>
                    <button type="button" @click="ask = true" class="min-h-11 font-semibold text-rose-700">I lost my card</button>
                </div>
                <div x-show="ask" x-cloak>
                    <p class="text-slate-700">We make a new card. The old one stops working at once (its QR says "Not a valid card").</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button" wire:click="newCard" @click="ask = false" class="min-h-11 rounded-xl bg-rose-600 px-4 font-semibold text-white">Yes, make a new card</button>
                        <button type="button" @click="ask = false" class="min-h-11 rounded-xl border border-slate-300 px-4 font-semibold text-slate-700">Cancel</button>
                    </div>
                </div>
            </div>
        @endif
    @endif
</div>
