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
                    <p class="font-semibold">🪪 Your card</p>
                    <p class="text-sm text-slate-600">It appears here, ready to show and to print.</p>
                </div>
            </li>
        </ol>
    @elseif ($status === 'not_active')
        <div class="rounded-2xl border border-rose-200 bg-rose-50 p-5 text-sm text-rose-800" data-testid="id-card-not-active">
            Your account is not active right now, so your ID card does not work. Please call your 1CallFix office.
        </div>
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
                <button type="button" x-show="! ask" @click="ask = true" class="min-h-11 font-semibold text-rose-700">I lost my card</button>
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
