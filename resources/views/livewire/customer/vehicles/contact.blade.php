<?php $t = fn ($k, $r = []) => \App\Support\SiteText::get($k, $r); ?>
<div class="space-y-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" data-testid="vehicle-contact">
    <div>
        <p class="text-2xl font-bold text-sky-700">{{ $price }} <span class="text-sm font-medium text-slate-500">{{ $t('vehicles.show.per_day_short') }}</span></p>
        @if ($hour) <p class="text-sm text-slate-600">{{ $t('vehicles.show.per_hour') }}: {{ $hour }}</p> @endif
        @if ($deposit) <p class="text-sm text-slate-600">{{ $t('vehicles.show.deposit') }}: {{ $deposit }}</p> @endif
    </div>

    @if ($error) <p role="alert" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" data-testid="vehicle-contact-error">{{ $error }}</p> @endif

    @if ($own)
        <p class="rounded-lg bg-slate-100 px-3 py-2 text-sm text-slate-700">{{ $t('vehicles.contact.own') }}</p>
    @elseif (! $phoneAllowed)
        <p class="rounded-lg bg-slate-100 px-3 py-2 text-sm text-slate-700">{{ $t('vehicles.contact.off') }}</p>
    @elseif (! auth()->check())
        <a href="{{ $signInUrl }}" wire:navigate class="flex min-h-12 w-full items-center justify-center rounded-xl bg-sky-600 px-4 font-semibold text-white hover:bg-sky-700" data-testid="vehicle-sign-in">{{ $t('vehicles.contact.sign_in') }}</a>
    @elseif ($ownerPhone)
        <a href="tel:{{ $ownerPhone }}" class="flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-sky-600 px-4 font-semibold text-white hover:bg-sky-700" data-testid="vehicle-owner-phone">📞 {{ $t('vehicles.contact.call') }} {{ $ownerPhone }}</a>
        @if ($whatsapp)
            <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="flex min-h-12 w-full items-center justify-center gap-2 rounded-xl border-2 border-emerald-600 bg-white px-4 font-semibold text-emerald-700" data-testid="vehicle-whatsapp">💬 {{ $t('vehicles.contact.whatsapp') }}</a>
        @endif
    @else
        <button type="button" wire:click="showPhone" wire:loading.attr="disabled" class="flex min-h-12 w-full items-center justify-center rounded-xl bg-sky-600 px-4 font-semibold text-white hover:bg-sky-700" data-testid="vehicle-show-phone">📞 {{ $t('vehicles.contact.show_phone') }}</button>
    @endif
    <p class="text-xs text-slate-500">{{ $t('vehicles.contact.free') }}</p>
</div>
