<div class="space-y-4">
    <h1 class="text-2xl font-bold tracking-tight">Visits</h1>
    <p class="text-sm text-slate-600">People who want to see your home. Accept to share the address and your number.</p>

    <div class="flex gap-2 overflow-x-auto" role="tablist">
        @foreach (['new' => 'New ('.$counts['new'].')', 'accepted' => 'Accepted ('.$counts['accepted'].')', 'past' => 'Past'] as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')" role="tab" @class(['min-h-11 shrink-0 rounded-full px-4 text-sm font-semibold',
                'bg-blue-600 text-white' => $tab === $key, 'border border-slate-300 bg-white text-slate-700' => $tab !== $key])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($error) <p role="alert" class="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{{ $error }}</p> @endif

    <ul class="space-y-3" data-testid="owner-visits">
        @forelse ($visits as $v)
            <li class="space-y-2 rounded-2xl border border-slate-200 bg-white p-4" wire:key="visit-{{ $v->id }}">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <p class="font-semibold">{{ $v->visit_date->format('D, j M') }} · {{ $v->slot }}</p>
                        <p class="text-sm text-slate-600">{{ $v->listing?->title }}</p>
                    </div>
                    <span @class(['rounded-full px-2.5 py-0.5 text-xs font-semibold',
                        'bg-amber-100 text-amber-800' => $v->status === 'requested', 'bg-emerald-100 text-emerald-800' => $v->status === 'accepted',
                        'bg-slate-100 text-slate-600' => ! in_array($v->status, ['requested', 'accepted'], true)])>{{ $v->statusLabel() }}</span>
                </div>
                <p class="text-sm"><span class="font-medium">{{ $v->customer_name }}</span>@if ($v->tenant_type) · {{ $v->tenant_type }}@endif</p>
                @if ($v->message) <p class="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700">“{{ $v->message }}”</p> @endif
                @if ($v->status === 'accepted')
                    <a href="tel:{{ $v->customer_phone }}" class="inline-flex min-h-11 items-center gap-2 rounded-xl bg-emerald-600 px-4 text-sm font-semibold text-white">📞 Call {{ $v->customer_phone }}</a>
                    @if ($v->owner_note) <p class="text-xs text-slate-500">Your note: {{ $v->owner_note }}</p> @endif
                @endif

                @if ($v->status === 'requested')
                    @if ($answeringId === $v->id)
                        <div class="space-y-3 rounded-xl border border-slate-200 p-3">
                            <label class="block text-sm font-medium text-slate-900">Note for the visitor <span class="font-normal text-slate-500">(optional, e.g. "Come at 5 PM, call when you reach")</span>
                                <input type="text" wire:model="note" maxlength="300" class="mt-1.5 block min-h-11 w-full rounded-xl border border-slate-300 px-3 text-base"></label>
                            <button type="button" wire:click="accept({{ $v->id }})" class="min-h-12 w-full rounded-xl bg-emerald-600 px-4 font-semibold text-white" data-testid="visit-accept">✓ Accept visit</button>
                            <div class="border-t pt-3">
                                <p class="text-sm font-medium text-slate-900">Or say no</p>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @foreach (\App\Livewire\Venue\Visits::REASONS as $r)
                                        <button type="button" wire:click="$set('reason', @js($r))" @class(['min-h-11 rounded-full border-2 px-4 text-sm font-medium', 'border-red-600 bg-red-600 text-white' => $reason === $r, 'border-slate-200 bg-white text-slate-700' => $reason !== $r])>{{ $r }}</button>
                                    @endforeach
                                </div>
                                <input type="text" wire:model="reason" maxlength="300" placeholder="Or type a reason" class="mt-2 block min-h-11 w-full rounded-xl border border-slate-300 px-3 text-base">
                                <button type="button" wire:click="decline" class="mt-2 min-h-11 w-full rounded-xl border border-red-300 bg-white px-4 font-semibold text-red-700" data-testid="visit-decline">Say no</button>
                            </div>
                        </div>
                    @else
                        <div class="flex gap-2">
                            <button type="button" wire:click="accept({{ $v->id }})" class="min-h-12 flex-1 rounded-xl bg-emerald-600 px-4 font-semibold text-white">✓ Accept</button>
                            <button type="button" wire:click="answer({{ $v->id }})" class="min-h-12 rounded-xl border border-slate-300 bg-white px-4 font-semibold text-slate-800">Note / say no</button>
                        </div>
                    @endif
                @endif
            </li>
        @empty
            <li class="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-600">Nothing here yet.</li>
        @endforelse
    </ul>
</div>
