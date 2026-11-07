@php
    use App\Support\InfoPages\FranchisePage as F;
    $input = 'w-full border rounded px-3 py-2 text-sm';
    $long = ['hero_subtitle', 'why_intro', 'earn_text', 'invest_note', 'cities_note', 'final_text', 'disclaimer', 'apply_done_body', 'seo_description'];
    // Screen order follows the page, top to bottom: each section's words, then its list.
    $sections = [
        'Top of the page (hero)' => [['hero_badge', 'hero_title', 'hero_subtitle', 'cta_label', 'call_label'], ['highlights']],
        'Numbers strip' => [[], ['stats']],
        'Why a franchise' => [['why_title', 'why_intro'], ['why']],
        'What you get' => [['support_title'], ['support']],
        'How you earn and investment' => [['earn_title', 'earn_text', 'invest_title', 'invest_note'], ['invest']],
        'Who we look for' => [['ideal_title'], ['ideal']],
        'Steps' => [['steps_title'], ['steps']],
        'Cities' => [['cities_title', 'cities_note'], ['cities']],
        'Partner stories' => [['stories_title'], ['stories']],
        'Questions' => [['faq_title'], ['faq']],
        'Closing band and small print' => [['final_title', 'final_text', 'disclaimer'], []],
        'Applying (on /partners)' => [['card_blurb', 'apply_done_title', 'apply_done_body'], []],
        'Google and sharing' => [['seo_title', 'seo_description'], []],
    ];
@endphp
<div>
    <div class="flex flex-wrap items-start justify-between gap-3 mb-1">
        <h1 class="text-xl font-semibold">Franchise page</h1>
        <a href="{{ route('customer.franchise') }}" target="_blank" rel="noopener" class="text-sm font-medium text-blue-700 underline">Open the page ↗</a>
    </div>
    <p class="text-sm text-gray-500 mb-4">
        Every word, list and picture on the public <code>/franchise</code> page. Super Admin only. A section whose list is
        empty is not shown (investment figures, cities and partner stories start empty: fill them only with real, agreed facts).
        Text containing <code>[</code> or <code>]</code> cannot be published. Every change is audit-logged.
        Applications arrive in <a class="underline" href="{{ route('admin.partner-leads.index') }}">Partner leads</a> as "Franchise owner".
    </p>

    @if ($flashMessage)
        <div role="status" class="rounded px-4 py-2 mb-4 text-sm bg-green-50 text-green-700">{{ $flashMessage }}</div>
    @endif
    @if ($errors->any())
        <div role="alert" class="rounded px-4 py-2 mb-4 text-sm bg-red-50 text-red-700">Some fields need fixing (marked in red below). Nothing was saved.</div>
    @endif

    <form wire:submit="save" class="space-y-6">
        <x-ui.card>
            <h2 class="text-sm font-semibold mb-3">On / off</h2>
            <div class="space-y-3 text-sm">
                <label class="flex items-start gap-2">
                    <input type="checkbox" wire:model="enabled" class="mt-1 rounded border-gray-300">
                    <span><span class="font-medium">Franchise page is live</span><br><span class="text-xs text-gray-500">Off: /franchise shows "page not found" and its footer link disappears.</span></span>
                </label>
                <label class="flex items-start gap-2">
                    <input type="checkbox" wire:model="accepting" class="mt-1 rounded border-gray-300">
                    <span><span class="font-medium">Take franchise applications</span><br><span class="text-xs text-gray-500">Shows the "Franchise owner" card in the /partners form. Off: the page stays, its Apply buttons are hidden.</span></span>
                </label>
                @foreach (F::SWITCHES as $key => [$label, $default])
                    <label class="flex items-start gap-2">
                        <input type="checkbox" wire:model="c.{{ $key }}" class="mt-1 rounded border-gray-300">
                        <span class="font-medium">{{ $label }}</span>
                    </label>
                @endforeach
            </div>
        </x-ui.card>

        <x-ui.card>
            <h2 class="text-sm font-semibold mb-1">Top picture (optional)</h2>
            <p class="text-xs text-gray-500 mb-3">Without one, the page shows a designed panel.</p>
            @if ($heroUrl)
                <div class="mb-3 flex items-center gap-3">
                    <img src="{{ $heroUrl }}" alt="" class="h-20 w-32 rounded object-cover">
                    <button type="button" wire:click="removeHeroImage" class="text-sm text-red-600 underline">Remove</button>
                </div>
            @endif
            <input type="file" wire:model="heroImageFile" accept="image/jpeg,image/png,image/webp" class="text-sm">
            <x-ui.image-size use="franchise_hero" />
            @error('heroImageFile')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </x-ui.card>

        @foreach ($sections as $heading => [$texts, $lists])
            <x-ui.card>
                <h2 class="text-sm font-semibold mb-3">{{ $heading }}</h2>
                <div class="space-y-4">
                    @foreach ($texts as $key)
                        @php [$label, $max] = F::TEXT[$key]; @endphp
                        <div>
                            <label class="block text-sm font-medium mb-1" for="fp-{{ $key }}">{{ $label }}</label>
                            @if (in_array($key, $long, true))
                                <textarea id="fp-{{ $key }}" wire:model="c.{{ $key }}" rows="3" maxlength="{{ $max }}" @class([$input, 'border-red-500' => $errors->has('c.'.$key)])></textarea>
                            @else
                                <input id="fp-{{ $key }}" type="text" wire:model="c.{{ $key }}" maxlength="{{ $max }}" @class([$input, 'border-red-500' => $errors->has('c.'.$key)])>
                            @endif
                            @error('c.'.$key)<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>
                    @endforeach

                    @foreach ($lists as $list)
                        @php [$label, $maxItems, $fields] = F::LISTS[$list]; $rows = $c[$list] ?? []; @endphp
                        <div data-list="{{ $list }}">
                            <p class="text-sm font-medium mb-2">{{ $label }} <span class="text-xs font-normal text-gray-500">({{ count($rows) }} of {{ $maxItems }})</span></p>
                            @error('c.'.$list)<p class="mb-2 text-xs text-red-600">{{ $message }}</p>@enderror
                            <div class="space-y-2">
                                @foreach ($rows as $i => $row)
                                    <div class="rounded border border-gray-200 p-3" wire:key="{{ $list }}-{{ $i }}">
                                        <div class="grid gap-2 {{ count($fields) > 1 ? 'sm:grid-cols-2' : '' }}">
                                            @foreach ($fields as $field => $fmax)
                                                <div @class(['sm:col-span-2' => in_array($field, ['text', 'quote', 'a'], true) && count($fields) > 1])>
                                                    <label class="block text-xs text-gray-500 mb-0.5" for="fp-{{ $list }}-{{ $i }}-{{ $field }}">{{ ['q' => 'Question', 'a' => 'Answer', 'text' => 'Text', 'title' => 'Title', 'icon' => 'Icon', 'value' => 'Value', 'label' => 'Label', 'name' => 'Name', 'city' => 'City', 'quote' => 'What they said'][$field] ?? $field }}</label>
                                                    @if ($field === 'icon')
                                                        <select id="fp-{{ $list }}-{{ $i }}-{{ $field }}" wire:model="c.{{ $list }}.{{ $i }}.icon" class="{{ $input }}">
                                                            @foreach (F::ICONS as $icon)<option value="{{ $icon }}">{{ $icon }}</option>@endforeach
                                                        </select>
                                                    @elseif ($fmax > 200)
                                                        <textarea id="fp-{{ $list }}-{{ $i }}-{{ $field }}" wire:model="c.{{ $list }}.{{ $i }}.{{ $field }}" rows="2" maxlength="{{ $fmax }}" @class([$input, 'border-red-500' => $errors->has("c.{$list}.{$i}.{$field}")])></textarea>
                                                    @else
                                                        <input id="fp-{{ $list }}-{{ $i }}-{{ $field }}" type="text" wire:model="c.{{ $list }}.{{ $i }}.{{ $field }}" maxlength="{{ $fmax }}" @class([$input, 'border-red-500' => $errors->has("c.{$list}.{$i}.{$field}")])>
                                                    @endif
                                                    @error("c.{$list}.{$i}.{$field}")<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                                                </div>
                                            @endforeach
                                        </div>
                                        <div class="mt-2 flex gap-3 text-xs">
                                            @if ($i > 0)<button type="button" wire:click="moveUp('{{ $list }}', {{ $i }})" class="text-gray-600 underline">Move up</button>@endif
                                            <button type="button" wire:click="removeRow('{{ $list }}', {{ $i }})" class="text-red-600 underline">Remove</button>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                            @if (count($rows) < $maxItems)
                                <button type="button" wire:click="addRow('{{ $list }}')" class="mt-2 rounded border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50">+ Add</button>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-ui.card>
        @endforeach

        <div class="flex flex-wrap items-center gap-3">
            <button type="submit" class="rounded bg-blue-600 px-5 py-2 text-sm font-semibold text-white hover:bg-blue-700" wire:loading.attr="disabled">Save</button>
            <button type="button" wire:click="resetToDefaults" wire:confirm="Load the built-in wording? Your changes stay until you press Save." class="text-sm text-gray-600 underline">Load the built-in wording</button>
        </div>
    </form>
</div>
