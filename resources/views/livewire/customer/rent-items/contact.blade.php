<?php $t = fn ($k, $r = []) => \App\Support\PostTypes::text($deal, preg_replace('/^items\./', '', $k), $r); // Rent anything + Second-hand (2026-11-19) ?>
<div class="space-y-3">
    <div class="space-y-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" data-testid="rent-contact">
        <div>
            <p class="text-2xl font-bold text-violet-700">{{ $price }}</p>
            @if ($negotiable) <p class="text-sm font-medium text-violet-800" data-testid="rent-negotiable">{{ $t('show.negotiable') }}</p> @endif
            @if ($deposit) <p class="text-sm text-slate-600">{{ $t('items.show.deposit') }}: {{ $deposit }}</p> @endif
        </div>

        @if ($error) <p role="alert" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" data-testid="rent-contact-error">{{ $error }}</p> @endif

        @if ($own)
            <p class="rounded-lg bg-slate-100 px-3 py-2 text-sm text-slate-700">{{ $t('items.contact.own') }}</p>
        @elseif (! $phoneAllowed)
            <p class="rounded-lg bg-slate-100 px-3 py-2 text-sm text-slate-700">{{ $t('items.contact.off') }}</p>
        @elseif (! auth()->check())
            <a href="{{ $signInUrl }}" wire:navigate class="flex min-h-12 w-full items-center justify-center rounded-xl bg-violet-600 px-4 font-semibold text-white hover:bg-violet-700" data-testid="rent-sign-in">{{ $t('items.contact.sign_in') }}</a>
        @elseif ($ownerPhone)
            <a href="tel:{{ $ownerPhone }}" class="flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-violet-600 px-4 font-semibold text-white hover:bg-violet-700" data-testid="rent-owner-phone">📞 {{ $t('items.contact.call') }} {{ $ownerPhone }}</a>
            @if ($whatsapp)
                <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="flex min-h-12 w-full items-center justify-center gap-2 rounded-xl border-2 border-emerald-600 bg-white px-4 font-semibold text-emerald-700" data-testid="rent-whatsapp">💬 {{ $t('items.contact.whatsapp') }}</a>
            @endif
        @else
            <button type="button" wire:click="showPhone" wire:loading.attr="disabled" class="flex min-h-12 w-full items-center justify-center rounded-xl bg-violet-600 px-4 font-semibold text-white hover:bg-violet-700" data-testid="rent-show-phone">📞 {{ $t('items.contact.show_phone') }}</button>
        @endif
        <p class="text-xs text-slate-500">{{ $t('items.contact.free') }}</p>
    </div>

    @unless ($own)
        <div class="rounded-2xl border border-slate-200 bg-white p-4 text-sm" data-testid="rent-report">
            @if ($reported)
                <p role="status" class="text-emerald-800">✅ {{ $reported }}</p>
            @elseif ($alreadyReported)
                <p class="text-slate-600">✅ {{ $t('items.report.thanks') }}</p>
            @elseif (! auth()->check())
                <a href="{{ $signInUrl }}" wire:navigate class="font-medium text-slate-600 underline">🚩 {{ $t('items.report.sign_in') }}</a>
            @elseif (! $reporting)
                <button type="button" wire:click="$set('reporting', true)" class="min-h-11 font-medium text-slate-600 underline" data-testid="rent-report-open">🚩 {{ $t('items.report.button') }}</button>
            @else
                <p class="font-semibold text-slate-900">{{ $t('items.report.title') }}</p>
                <div class="mt-2 flex flex-wrap gap-2" role="radiogroup" aria-label="{{ $t('items.report.title') }}">
                    @foreach ($reasons as $key => $label)
                        <label class="inline-flex min-h-11 cursor-pointer items-center rounded-full border-2 border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 has-[:checked]:border-rose-600 has-[:checked]:bg-rose-600 has-[:checked]:text-white">
                            <input type="radio" wire:model="reason" value="{{ $key }}" class="sr-only"> {{ $label }}
                        </label>
                    @endforeach
                </div>
                @error('reason') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                <label for="rent-report-note" class="mt-3 block text-sm font-medium text-slate-900">{{ $t('items.report.note') }}</label>
                <textarea id="rent-report-note" wire:model="note" rows="2" maxlength="500" class="mt-1.5 block w-full rounded-xl border border-slate-300 px-3 py-2 text-base"></textarea>
                <div class="mt-3 flex gap-2">
                    <button type="button" wire:click="sendReport" wire:loading.attr="disabled" class="min-h-11 flex-1 rounded-xl bg-rose-600 px-4 font-semibold text-white" data-testid="rent-report-send">{{ $t('items.report.send') }}</button>
                    <button type="button" wire:click="$set('reporting', false)" class="min-h-11 rounded-xl border border-slate-300 px-4 font-semibold text-slate-700">Cancel</button>
                </div>
            @endif
        </div>
    @endunless
</div>
