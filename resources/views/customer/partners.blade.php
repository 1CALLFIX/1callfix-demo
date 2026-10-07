{{--
    Public partner page (REF 1CF-PARTNER-PAGE-001). This view holds NO wording: role cards come from the module
    registry and every text block and list from the settings store (App\Support\PartnerPage\PartnerPageData). A block
    whose value is empty is not rendered. Visual language follows the owner's design (dark hero and benefit band,
    light sections, coloured benefit icons, tabbed FAQ).
--}}
@php
    $tones = [
        'blue' => 'bg-blue-100 text-blue-700', 'green' => 'bg-emerald-100 text-emerald-700', 'amber' => 'bg-amber-100 text-amber-700',
        'rose' => 'bg-rose-100 text-rose-700', 'violet' => 'bg-violet-100 text-violet-700', 'teal' => 'bg-teal-100 text-teal-700',
    ];
    $firstTab = array_key_first($faqTabs);
@endphp

<x-layouts.customer :title="$seoTitle" :indexable="true" :metaDescription="$seoDescription">

    {{-- Hero --}}
    <section class="bg-slate-900 text-white">
        <div class="mx-auto max-w-5xl px-4 py-16 sm:px-6 sm:py-24 lg:px-8">
            <h1 class="max-w-3xl text-4xl font-extrabold tracking-tight sm:text-5xl">{{ $heroTitle }}</h1>
            @if (filled($heroSubtitle))
                <p class="mt-5 max-w-2xl text-base leading-relaxed text-slate-300 sm:text-lg">{{ $heroSubtitle }}</p>
            @endif
            <div class="mt-8 flex flex-wrap items-center gap-3">
                <a href="#apply" class="inline-flex min-h-11 items-center rounded-lg bg-amber-400 px-6 py-3 text-sm font-semibold text-slate-900 transition hover:bg-amber-300 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-300">{{ $heroCta }}</a>
                @if ($androidUrl)
                    <a href="{{ $androidUrl }}" rel="noopener" class="inline-flex min-h-11 items-center rounded-lg border border-slate-600 px-5 py-3 text-sm font-semibold text-white hover:bg-slate-800">Get the Android app</a>
                @endif
                @if ($iosUrl)
                    <a href="{{ $iosUrl }}" rel="noopener" class="inline-flex min-h-11 items-center rounded-lg border border-slate-600 px-5 py-3 text-sm font-semibold text-white hover:bg-slate-800">Get the iPhone app</a>
                @endif
            </div>
            @isset($claims['joining_free'])
                <p class="mt-4 text-sm font-medium text-amber-300">{{ $claims['joining_free'] }}</p>
            @endisset
        </div>
    </section>

    @if (filled($band))
        <section class="border-b border-amber-200 bg-amber-50">
            <p class="mx-auto max-w-5xl px-4 py-3 text-sm font-medium text-amber-900 sm:px-6 lg:px-8">{{ $band }}</p>
        </section>
    @endif

    {{-- Role cards (module registry) --}}
    @if ($roles !== [])
        <section aria-labelledby="roles-heading" class="mx-auto max-w-5xl px-4 py-14 sm:px-6 lg:px-8">
            <h2 id="roles-heading" class="text-3xl font-extrabold tracking-tight text-slate-900">Pick your role</h2>
            <ul class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($roles as $role)
                    <li>
                        <a href="#apply" data-role="{{ $role['code'] }}" x-data x-on:click="$dispatch('partner-role-select', { role: '{{ $role['code'] }}' })"
                           class="flex h-full flex-col rounded-2xl border border-slate-200 bg-white p-5 transition hover:border-blue-400 hover:shadow-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                            <span @class([
                                'w-fit rounded-full px-2.5 py-0.5 text-xs font-semibold',
                                'bg-emerald-100 text-emerald-700' => $role['live'],
                                'bg-slate-100 text-slate-600' => ! $role['live'],
                            ])>{{ $role['live'] ? 'Live' : 'Opening soon' }}</span>
                            <h3 class="mt-3 text-lg font-bold text-slate-900">{{ $role['label'] }}</h3>
                            <p class="mt-1 text-sm text-slate-600">{{ $role['blurb'] }}</p>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- Steps and what you will need --}}
    @if ($steps !== [] || $needs !== [])
        <section id="how-it-works" aria-labelledby="steps-heading" class="border-y border-slate-200 bg-stone-50">
            <div class="mx-auto grid max-w-5xl gap-10 px-4 py-14 sm:px-6 lg:grid-cols-3 lg:px-8">
                @if ($steps !== [])
                    <div class="lg:col-span-2">
                        <h2 id="steps-heading" class="text-3xl font-extrabold tracking-tight text-slate-900">How it works</h2>
                        <ol class="mt-8 space-y-6">
                            @foreach ($steps as $i => $step)
                                <li class="flex gap-4">
                                    <span aria-hidden="true" class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-slate-900 text-sm font-bold text-white">{{ $i + 1 }}</span>
                                    <div>
                                        <h3 class="text-lg font-bold text-slate-900"><span class="sr-only">Step {{ $i + 1 }}: </span>{{ $step['title'] }}</h3>
                                        <p class="mt-1 text-sm leading-relaxed text-slate-600">{{ $step['body'] }}</p>
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    </div>
                @endif
                @if ($needs !== [] || filled($approvalTime) || isset($claims['save_finish_later']))
                    <div class="rounded-2xl border border-slate-200 bg-white p-6">
                        @if ($needs !== [])
                            <h3 class="text-lg font-bold text-slate-900">What you will need</h3>
                            <ul class="mt-3 list-disc space-y-2 pl-5 text-sm text-slate-700">
                                @foreach ($needs as $need)
                                    <li>{{ $need }}</li>
                                @endforeach
                            </ul>
                        @endif
                        @if (filled($approvalTime))
                            <p class="mt-4 text-sm text-slate-600">{{ $approvalTime }}</p>
                        @endif
                        @isset($claims['save_finish_later'])
                            <p class="mt-4 text-sm text-slate-600">{{ $claims['save_finish_later'] }}</p>
                        @endisset
                    </div>
                @endif
            </div>
        </section>
    @endif

    {{-- Benefits --}}
    @if ($benefits !== [])
        <section aria-labelledby="benefits-heading" class="bg-slate-900 text-white">
            <div class="mx-auto max-w-5xl px-4 py-14 sm:px-6 lg:px-8">
                <h2 id="benefits-heading" class="text-3xl font-extrabold tracking-tight">Built so partners can do their best work</h2>
                <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($benefits as $b)
                        <div class="rounded-2xl bg-slate-800 p-5">
                            <span aria-hidden="true" class="grid h-11 w-11 place-items-center rounded-xl {{ $tones[$b['color']] ?? $tones['blue'] }}">
                                <x-icon :name="$b['icon']" class="h-5 w-5" />
                            </span>
                            <h3 class="mt-4 text-lg font-bold">{{ $b['title'] }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-slate-300">{{ $b['body'] }}</p>
                        </div>
                    @endforeach
                </div>
                @foreach (['company_accounts', 'whatsapp_updates'] as $claim)
                    @isset($claims[$claim])
                        <p class="mt-4 text-sm text-slate-300">{{ $claims[$claim] }}</p>
                    @endisset
                @endforeach
            </div>
        </section>
    @endif

    {{-- Commission and payout notes --}}
    @if (filled($commission) || filled($payoutTiming))
        <section class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8">
            <div class="space-y-2 rounded-2xl border border-slate-200 bg-white p-6 text-sm text-slate-700">
                @if (filled($commission)) <p>{{ $commission }}</p> @endif
                @if (filled($payoutTiming)) <p>{{ $payoutTiming }}</p> @endif
            </div>
        </section>
    @endif

    {{-- FAQ (tabs) --}}
    @if ($faqTabs !== [])
        <section aria-labelledby="faq-heading" class="mx-auto max-w-3xl px-4 py-14 sm:px-6 lg:px-8" x-data="{ tab: '{{ $firstTab }}' }">
            <h2 id="faq-heading" class="text-3xl font-extrabold tracking-tight text-slate-900">Every question, one place</h2>
            <div role="tablist" class="mt-6 flex flex-wrap gap-2">
                @foreach ($faqTabs as $key => $group)
                    <button type="button" role="tab" id="faq-tab-{{ $key }}" aria-controls="faq-panel-{{ $key }}"
                            x-on:click="tab = '{{ $key }}'" x-bind:aria-selected="tab === '{{ $key }}'"
                            x-bind:class="tab === '{{ $key }}' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700'"
                            class="min-h-11 rounded-full px-4 py-2 text-sm font-semibold">{{ $group['label'] }}</button>
                @endforeach
            </div>
            @foreach ($faqTabs as $key => $group)
                <div role="tabpanel" id="faq-panel-{{ $key }}" aria-labelledby="faq-tab-{{ $key }}" x-show="tab === '{{ $key }}'" @if ($key !== $firstTab) x-cloak @endif class="mt-4 divide-y divide-slate-200 rounded-2xl border border-slate-200 bg-white">
                    @foreach ($group['items'] as $item)
                        <details class="group p-5">
                            <summary class="cursor-pointer list-none text-base font-semibold text-slate-900">{{ $item['q'] }}</summary>
                            <p class="mt-2 text-sm leading-relaxed text-slate-600">{{ $item['a'] }}</p>
                        </details>
                    @endforeach
                </div>
            @endforeach
        </section>
    @endif

    {{-- Application form --}}
    <section id="apply" aria-labelledby="apply-heading" class="bg-slate-900 text-white">
        <div class="mx-auto max-w-5xl px-4 py-14 sm:px-6 lg:px-8">
            <h2 id="apply-heading" class="text-3xl font-extrabold tracking-tight">{{ $heroCta }}</h2>

            <livewire:partner.apply-form />
            @foreach ($phones as $phone)
                <p class="mt-2 text-sm text-slate-300">
                    @if ($phone['tel'] !== '') <a class="underline" href="tel:{{ $phone['tel'] }}">{{ $phone['text'] }}</a> @else {{ $phone['text'] }} @endif
                </p>
            @endforeach
            @if (filled($footerContact))
                <p class="mt-2 text-sm text-slate-300">{{ $footerContact }}</p>
            @endif
            <p class="mt-6 text-sm text-slate-300">
                Already a partner?
                <a href="{{ route('provider.login') }}" class="font-semibold text-white underline underline-offset-4">Sign in</a>
            </p>
        </div>
    </section>

</x-layouts.customer>
