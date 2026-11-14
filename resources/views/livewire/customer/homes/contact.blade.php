<?php
    $t = fn ($k, $r = []) => \App\Support\SiteText::get($k, $r);
    $chip = 'min-h-11 shrink-0 rounded-full border-2 px-4 text-sm font-medium';
?>
<div class="space-y-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" data-testid="home-contact">
    <div>
        <p class="text-2xl font-bold text-emerald-700">{{ $rent }} <span class="text-sm font-medium text-slate-500">{{ $t('homes.show.per_month') }}</span></p>
        @if ($deposit) <p class="text-sm text-slate-600">{{ $t('homes.show.deposit') }}: {{ $deposit }}</p> @endif
    </div>

    @if ($error) <p role="alert" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" data-testid="home-contact-error">{{ $error }}</p> @endif
    @if ($done) <p role="status" class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800" data-testid="home-asked">{{ $done }}</p> @endif

    @if ($own)
        <p class="rounded-lg bg-slate-100 px-3 py-2 text-sm text-slate-700">{{ $t('homes.contact.own') }}</p>
    @elseif (! auth()->check())
        <a href="{{ $signInUrl }}" wire:navigate class="flex min-h-12 w-full items-center justify-center rounded-xl bg-emerald-600 px-4 font-semibold text-white hover:bg-emerald-700" data-testid="home-sign-in">{{ $t('homes.contact.sign_in') }}</a>
    @else
        @if ($openVisit)
            <a href="{{ route('customer.homes.visits') }}" wire:navigate class="block rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900">
                <span class="font-semibold">{{ $openVisit->statusLabel() }}</span> · {{ $openVisit->visit_date->format('D, j M') }} · {{ $openVisit->slot }} →
            </a>
        @elseif ($asking)
            <div class="space-y-3" data-testid="home-visit-form">
                <div>
                    <p class="text-sm font-medium text-slate-900">{{ $t('homes.contact.day') }}</p>
                    <div class="mt-1.5 flex gap-2 overflow-x-auto pb-1">
                        @foreach ($days as $value => $label)
                            <button type="button" wire:click="$set('date', '{{ $value }}')" @class([$chip, 'border-emerald-600 bg-emerald-600 text-white' => $date === $value, 'border-slate-200 bg-white text-slate-700' => $date !== $value])>{{ $label }}</button>
                        @endforeach
                    </div>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-900">{{ $t('homes.contact.time') }}</p>
                    <div class="mt-1.5 flex flex-wrap gap-2">
                        @foreach ($slots as $s)
                            <button type="button" wire:click="$set('slot', @js($s))" @class([$chip, 'border-emerald-600 bg-emerald-600 text-white' => $slot === $s, 'border-slate-200 bg-white text-slate-700' => $slot !== $s])>{{ $s }}</button>
                        @endforeach
                    </div>
                </div>
                @if ($tenantChoices !== [])
                    <div>
                        <p class="text-sm font-medium text-slate-900">{{ $t('homes.contact.you_are') }} <span class="font-normal text-slate-500">({{ $t('homes.contact.optional') }})</span></p>
                        <div class="mt-1.5 flex flex-wrap gap-2">
                            @foreach ($tenantChoices as $w)
                                <button type="button" wire:click="$set('tenantType', @js($tenantType === $w ? '' : $w))" @class([$chip, 'border-violet-600 bg-violet-600 text-white' => $tenantType === $w, 'border-slate-200 bg-white text-slate-700' => $tenantType !== $w])>{{ $w }}</button>
                            @endforeach
                        </div>
                    </div>
                @endif
                <label class="block text-sm font-medium text-slate-700">{{ $t('homes.contact.name') }}
                    <input type="text" wire:model="name" class="mt-1 block min-h-11 w-full rounded-xl border border-slate-300 px-3 text-base"></label>
                @error('name') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
                <label class="block text-sm font-medium text-slate-700">{{ $t('homes.contact.phone') }}
                    <input type="tel" wire:model="phone" class="mt-1 block min-h-11 w-full rounded-xl border border-slate-300 px-3 text-base"></label>
                @error('phone') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
                <label class="block text-sm font-medium text-slate-700">{{ $t('homes.contact.message') }} <span class="font-normal text-slate-500">({{ $t('homes.contact.optional') }})</span>
                    <textarea wire:model="message" rows="2" maxlength="500" class="mt-1 block w-full rounded-xl border border-slate-300 px-3 py-2 text-base"></textarea></label>
                <button type="button" wire:click="ask" wire:loading.attr="disabled" class="flex min-h-12 w-full items-center justify-center rounded-xl bg-emerald-600 px-4 font-semibold text-white hover:bg-emerald-700" data-testid="home-send-visit">{{ $t('homes.contact.send') }}</button>
            </div>
        @else
            <button type="button" wire:click="startAsking" class="flex min-h-12 w-full items-center justify-center rounded-xl bg-emerald-600 px-4 font-semibold text-white hover:bg-emerald-700" data-testid="home-ask-visit">🗓️ {{ $t('homes.contact.ask') }}</button>
        @endif

        @if ($phoneAllowed)
            @if ($ownerPhone)
                <a href="tel:{{ $ownerPhone }}" class="flex min-h-12 w-full items-center justify-center gap-2 rounded-xl border-2 border-emerald-600 bg-white px-4 font-semibold text-emerald-700" data-testid="home-owner-phone">📞 {{ $ownerPhone }}</a>
            @else
                <button type="button" wire:click="showPhone" class="flex min-h-12 w-full items-center justify-center rounded-xl border border-slate-300 bg-white px-4 font-semibold text-slate-800" data-testid="home-show-phone">📞 {{ $t('homes.contact.show_phone') }}</button>
            @endif
        @endif
    @endif
    <p class="text-xs text-slate-500">{{ $t('homes.contact.free') }}</p>
</div>
