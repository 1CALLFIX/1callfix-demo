<?php $t = fn ($k, $r = []) => \App\Support\SiteText::get($k, $r); ?>
<div class="mx-auto max-w-2xl px-4 py-6 sm:px-6">
    <h1 class="text-2xl font-bold tracking-tight">{{ $t('homes.visits.title') }}</h1>
    @if ($error) <p role="alert" class="mt-3 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{{ $error }}</p> @endif
    <ul class="mt-4 space-y-3" data-testid="customer-home-visits">
        @forelse ($visits as $v)
            <?php $home = $v->listing; $url = $home?->franchise?->city ? route('customer.homes.show', [$home->franchise->city->slug, $home->slug]) : null; ?>
            <li class="space-y-2 rounded-2xl border border-slate-200 bg-white p-4" wire:key="cv-{{ $v->id }}">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        @if ($url) <a href="{{ $url }}" wire:navigate class="font-semibold hover:underline">{{ $home?->title }}</a> @else <p class="font-semibold">{{ $home?->title }}</p> @endif
                        <p class="text-sm text-slate-600">{{ $v->visit_date->format('D, j M') }} · {{ $v->slot }}</p>
                    </div>
                    <span @class(['shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold',
                        'bg-amber-100 text-amber-800' => $v->status === 'requested', 'bg-emerald-100 text-emerald-800' => $v->status === 'accepted',
                        'bg-slate-100 text-slate-600' => ! in_array($v->status, ['requested', 'accepted'], true)])>{{ $v->statusLabel() }}</span>
                </div>
                @if ($v->owner_note) <p class="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700">{{ $t('homes.visits.owner_says') }} “{{ $v->owner_note }}”</p> @endif
                @if ($v->status === 'accepted' && $home)
                    <div class="space-y-2 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900" data-testid="visit-address">
                        <p>📍 {{ $home->address_line }}</p>
                        <div class="flex flex-wrap gap-2">
                            <a href="{{ $home->lat !== null ? 'https://www.google.com/maps/search/?api=1&query='.$home->lat.','.$home->lng : 'https://www.google.com/maps/search/?api=1&query='.urlencode($home->address_line) }}" target="_blank" rel="noopener" class="inline-flex min-h-11 items-center rounded-xl bg-white px-4 font-semibold text-emerald-800">🧭 {{ $t('homes.visits.directions') }}</a>
                            @php $ph = $home->contact_phone ?: $home->provider?->user?->phone; @endphp
                            @if ($ph) <a href="tel:{{ $ph }}" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-600 px-4 font-semibold text-white">📞 {{ $ph }}</a> @endif
                        </div>
                    </div>
                @endif
                @if (in_array($v->status, \App\Models\HouseVisit::OPEN, true))
                    <button type="button" wire:click="cancel({{ $v->id }})" wire:confirm="{{ $t('homes.visits.cancel_confirm') }}" class="min-h-11 text-sm font-medium text-red-700 underline">{{ $t('homes.visits.cancel') }}</button>
                @endif
            </li>
        @empty
            <li class="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-600">{{ $t('homes.visits.empty') }}</li>
        @endforelse
    </ul>
</div>
