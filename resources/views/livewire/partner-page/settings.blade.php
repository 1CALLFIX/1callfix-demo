@php
    use App\Support\PartnerPage\PartnerPageSettings as P;
    use App\Livewire\PartnerPage\Settings as S;
    $input = 'w-full border rounded px-3 py-2 text-sm';
    $textareaKeys = ['hero.subtitle', 'nellore_band.text', 'commission.note', 'needs', 'support_phones', 'form.consent_text', 'form.waitlist_note', 'form.done_body', 'seo.description', 'payout_timing', 'approval_time', 'footer_contact', 'hero.title', 'hero.ticks', 'roles.lead', 'join.lead'];
    $groups = [
        'Hero and band' => ['hero.badge', 'hero.title', 'hero.subtitle', 'hero.cta_label', 'hero.ticks', 'nellore_band.text'],
        'Section intros' => ['roles.lead', 'join.lead'],
        'Commission, payout and approval' => ['commission.min', 'commission.max', 'commission.note', 'payout_timing', 'approval_time'],
        'Support and footer' => ['support_phones', 'footer_contact', 'needs'],
        'Application form' => ['form.consent_text', 'form.waitlist_note', 'form.done_title', 'form.done_body'],
        'App links (hidden when blank)' => ['store.android_url', 'store.ios_url'],
        'Search and social' => ['seo.title', 'seo.description'],
    ];
@endphp
<div>
    <h1 class="text-xl font-semibold mb-1">Partner page</h1>
    <p class="text-sm text-gray-500 mb-4">
        Every word and list on the public <code>/partners</code> page. A blank field falls back to the built-in wording, or hides the
        block when there is none. Text containing <code>[</code> or <code>]</code> cannot be published. Every change is audit-logged.
    </p>

    @if ($flashMessage)
        <div class="rounded px-4 py-2 mb-4 text-sm bg-green-50 text-green-700">{{ $flashMessage }}</div>
    @endif

    <form wire:submit="save" class="space-y-6">
        @foreach ($groups as $heading => $keys)
            <x-ui.card>
                <h2 class="text-sm font-semibold mb-3">{{ $heading }}</h2>
                @if ($heading === 'Commission, payout and approval')
                    <div class="mb-3 rounded px-3 py-2 text-sm {{ $feeOutsideRange ? 'bg-amber-50 text-amber-800' : 'bg-gray-50 text-gray-700' }}">
                        Current default platform fee (read-only, from Settings → Commission): <strong>{{ rtrim(rtrim(number_format($defaultFee, 2), '0'), '.') }}%</strong>.
                        @if ($feeOutsideRange)
                            Warning: this is outside the range shown on the page. The page text is display-only and never changes any commission.
                        @endif
                    </div>
                @endif
                <div class="grid gap-4 md:grid-cols-2">
                    @foreach ($keys as $short)
                        @php [$label] = P::TEXT[$short]; $field = S::field($short); @endphp
                        <div class="{{ in_array($short, $textareaKeys, true) ? 'md:col-span-2' : '' }}">
                            <label class="block text-sm font-medium mb-1" for="pp-{{ $field }}">{{ $label }}</label>
                            @if (in_array($short, $textareaKeys, true))
                                <textarea id="pp-{{ $field }}" rows="2" wire:model="f.{{ $field }}" placeholder="{{ P::defaults()[$short] ?? '' }}" class="{{ $input }}"></textarea>
                            @else
                                <input id="pp-{{ $field }}" type="text" wire:model="f.{{ $field }}" placeholder="{{ P::defaults()[$short] ?? '' }}" class="{{ $input }}">
                            @endif
                            @error('f.'.$field) <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                </div>
            </x-ui.card>
        @endforeach

        <x-ui.card>
            <h2 class="text-sm font-semibold mb-3">Role cards</h2>
            <p class="text-xs text-gray-500 mb-3">Cards are built from the module registry. A module that is not live shows "Opening soon" and takes waiting-list entries. Tick to hide a card.</p>
            <div class="grid gap-2 md:grid-cols-3">
                @foreach (P::ROLE_ORDER as $code)
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" value="{{ $code }}" wire:model="hidden">
                        Hide {{ P::roleWording()[$code]['label'] }}
                        <span class="text-xs text-gray-400">({{ $modules[$code]->is_implemented ?? false ? 'live' : 'opening soon' }})</span>
                    </label>
                @endforeach
            </div>
            @error('f.modules_hidden') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </x-ui.card>

        <x-ui.card>
            <h2 class="text-sm font-semibold mb-3">Blocks and claims</h2>
            <div class="grid gap-2 md:grid-cols-2">
                @foreach (P::SWITCHES as $short => [$label, $default])
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model="sw.{{ S::field($short) }}"> {{ $label }}
                        <span class="text-xs text-gray-400">(default {{ $default ? 'on' : 'off' }})</span>
                    </label>
                @endforeach
            </div>
            <p class="text-xs text-gray-500 mt-3">Claims stay off until you turn them on. Only switch one on when the product really does it.</p>
        </x-ui.card>

        <x-ui.card>
            <h2 class="text-sm font-semibold mb-3">Lists (JSON, blank = built-in)</h2>
            <div class="space-y-4">
                @foreach ([
                    'steps' => '[{"title":"…","body":"…"}] 1 to 8 steps',
                    'benefits' => '[{"icon":"clipboard","color":"blue","title":"…","body":"…"}] 1 to 12 tiles. Icons: '.implode(', ', array_keys(\App\Models\PartnerBenefit::ICONS)).'. Colours: '.implode(', ', P::COLORS),
                    'faq' => '[{"tab":"everyone","q":"…","a":"…"}] up to 40. Tabs: '.implode(', ', array_keys(P::FAQ_TABS)),
                    'footer.groups' => '[{"title":"Company","links":[{"label":"…","href":"/help"}]}] up to 6 groups of 1 to 12 links. Blank = the built-in footer. CMS pages marked "show in footer" are still added to Company.',
                    'role_cards' => '{"service":{"label":"…","blurb":"…"}} optional wording per role code',
                ] as $short => $hint)
                    @php $field = S::field($short); @endphp
                    <div>
                        <label class="block text-sm font-medium mb-1" for="pp-{{ $field }}">{{ P::JSON[$short] }}</label>
                        <textarea id="pp-{{ $field }}" rows="5" wire:model="f.{{ $field }}" class="{{ $input }} font-mono text-xs"></textarea>
                        <p class="text-xs text-gray-500 mt-1">{{ $hint }}</p>
                        @error('f.'.$field) <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </div>
        </x-ui.card>

        <x-ui.card>
            <h2 class="text-sm font-semibold mb-3">Partner leads</h2>
            <label class="block text-sm font-medium mb-1" for="pp-retention">Keep leads for (days)</label>
            <input id="pp-retention" type="text" wire:model="f.{{ S::field(P::LEAD_RETENTION) }}" placeholder="blank = keep forever" class="{{ $input }} md:w-64">
            @error('f.'.S::field(P::LEAD_RETENTION)) <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </x-ui.card>

        <button type="submit" class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white">Save Partner page</button>
    </form>
</div>
