@php
    use App\Support\InfoPages\CareersPage;
    $brand = \App\Services\Seo\SeoSettings::siteName();
    $t = fn (string $key, array $r = []) => \App\Support\SiteText::get($key, $r + ['brand' => $brand]);
    $why = [
        ['icon' => 'map-pin', 'bg' => 'bg-blue-500', 'title' => $t('careers.why_1_title'), 'line' => $t('careers.why_1_line')],
        ['icon' => 'sparkles', 'bg' => 'bg-amber-500', 'title' => $t('careers.why_2_title'), 'line' => $t('careers.why_2_line')],
        ['icon' => 'users', 'bg' => 'bg-emerald-500', 'title' => $t('careers.why_3_title'), 'line' => $t('careers.why_3_line')],
    ];
    $steps = [
        ['n' => 1, 'bg' => 'bg-blue-600', 'title' => $t('careers.step_1'), 'line' => $t('careers.step_1_line')],
        ['n' => 2, 'bg' => 'bg-violet-600', 'title' => $t('careers.step_2'), 'line' => $t('careers.step_2_line')],
        ['n' => 3, 'bg' => 'bg-amber-500', 'title' => $t('careers.step_3'), 'line' => $t('careers.step_3_line')],
        ['n' => 4, 'bg' => 'bg-emerald-600', 'title' => $t('careers.step_4'), 'line' => $t('careers.step_4_line')],
    ];
@endphp
{{-- Careers page (owner 2026-11-21): the open jobs from Admin → Careers; words from SiteText careers.*. --}}
<x-layouts.customer :title="$t('careers.page_title')" :raw-title="true" :meta-description="$t('careers.page_meta')" :indexable="true">
<div class="mb-bottom-nav" data-careers-page>

    {{-- ============ Hero ============ --}}
    <section class="relative overflow-hidden bg-gradient-to-br from-blue-700 via-indigo-700 to-violet-700 text-white">
        <div aria-hidden="true" class="pointer-events-none absolute inset-0 opacity-[0.08]" style="background-image:radial-gradient(#fff 1.5px,transparent 1.5px);background-size:26px 26px"></div>
        <div aria-hidden="true" class="pointer-events-none absolute -right-20 -top-24 h-96 w-96 rounded-full bg-amber-300/25 blur-3xl"></div>
        <div class="relative mx-auto max-w-5xl px-4 pb-16 pt-12 sm:px-6 sm:pb-20 sm:pt-16 lg:px-8">
            <p class="inline-flex items-center gap-2 rounded-full bg-white/15 px-3 py-1 text-xs font-bold uppercase tracking-wider ring-1 ring-inset ring-white/25">
                <span class="h-2 w-2 animate-pulse rounded-full bg-emerald-300"></span>{{ $t('careers.hero_badge') }}
            </p>
            <h1 class="mt-4 text-4xl font-extrabold leading-tight tracking-tight sm:text-5xl">{{ $t('careers.hero_title') }}</h1>
            <p class="mt-4 max-w-2xl text-base leading-relaxed text-blue-50 sm:text-lg">{{ $t('careers.hero_line') }}</p>
            <div class="mt-7 flex flex-wrap items-center gap-3">
                <a href="#jobs" class="inline-flex min-h-12 items-center gap-2 rounded-xl bg-amber-400 px-6 text-base font-bold text-slate-900 shadow-lg shadow-amber-500/25 transition hover:bg-amber-300">
                    {{ $t('careers.hero_cta') }}
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M12 5v14M6 13l6 6 6-6"/></svg>
                </a>
                <x-customer.share-button :title="$t('careers.hero_title')" round />
            </div>
        </div>
    </section>

    {{-- ============ Why work with us ============ --}}
    <section class="relative mx-auto -mt-8 max-w-5xl px-4 sm:px-6 lg:px-8">
        <h2 class="sr-only">{{ $t('careers.why_title') }}</h2>
        <div class="grid gap-3 sm:grid-cols-3">
            @foreach ($why as $w)
                <div class="flex items-start gap-3 rounded-2xl bg-white p-4 shadow-lg ring-1 ring-slate-200">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl {{ $w['bg'] }} text-white"><x-icon :name="$w['icon']" class="h-6 w-6" /></span>
                    <div>
                        <p class="font-bold text-slate-900">{{ $w['title'] }}</p>
                        <p class="mt-0.5 text-sm text-slate-600">{{ $w['line'] }}</p>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    {{-- ============ Open jobs ============ --}}
    <section id="jobs" class="mx-auto max-w-5xl scroll-mt-20 px-4 pt-12 sm:px-6 lg:px-8">
        <div class="flex items-end justify-between gap-3">
            <h2 class="text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl">{{ $t('careers.jobs_title') }}</h2>
            @if ($jobs->isNotEmpty())
                <span class="rounded-full bg-blue-100 px-3 py-1 text-sm font-bold text-blue-800">{{ $jobs->count() }}</span>
            @endif
        </div>

        @if ($jobs->isEmpty())
            <p class="mt-4 rounded-2xl bg-slate-50 p-5 text-slate-700 ring-1 ring-slate-200">{{ $t('careers.no_jobs') }}</p>
        @else
            <div class="mt-5 grid gap-4 md:grid-cols-2">
                @foreach ($jobs as $job)
                    @php($c = CareersPage::colours($job->team))
                    <a href="{{ $job->url() }}" data-job-card="{{ $job->slug }}" class="group relative flex flex-col overflow-hidden rounded-2xl bg-white p-5 pl-6 shadow-sm ring-1 ring-slate-200 transition hover:-translate-y-0.5 hover:shadow-lg">
                        <span aria-hidden="true" class="absolute inset-y-0 left-0 w-1.5 {{ $c['bar'] }}"></span>
                        <div class="flex flex-wrap items-center gap-2 text-xs font-bold">
                            @if ($job->team)<span class="rounded-full px-2.5 py-1 {{ $c['badge'] }}">{{ $job->team }}</span>@endif
                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-slate-700">{{ $job->typeLabel() }}</span>
                            <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-slate-700"><x-icon name="map-pin" class="h-3.5 w-3.5" />{{ $job->location }}</span>
                        </div>
                        <h3 class="mt-3 text-lg font-extrabold leading-snug text-slate-900 group-hover:text-blue-700">{{ $job->title }}</h3>
                        @if ($job->summary)<p class="mt-1.5 line-clamp-3 text-sm text-slate-600">{{ $job->summary }}</p>@endif
                        <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
                            <span class="font-semibold text-slate-700">{{ $job->openings === 1 ? $t('careers.openings_one') : $t('careers.openings', ['count' => $job->openings]) }}</span>
                            @if ($job->pay_text)<span class="font-bold text-emerald-700">{{ $job->pay_text }}</span>@endif
                        </div>
                        <span class="mt-4 inline-flex min-h-11 items-center gap-1.5 self-start rounded-xl bg-blue-600 px-4 font-bold text-white group-hover:bg-blue-700">
                            {{ $t('careers.view_apply') }}
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                        </span>
                    </a>
                @endforeach
            </div>
        @endif
    </section>

    {{-- ============ Partners are not staff ============ --}}
    <section class="mx-auto max-w-5xl px-4 pt-10 sm:px-6 lg:px-8">
        <div class="flex flex-col items-start gap-4 rounded-3xl bg-gradient-to-r from-orange-500 to-amber-500 p-6 text-white shadow-lg sm:flex-row sm:items-center sm:justify-between sm:p-8">
            <div>
                <h2 class="text-xl font-extrabold sm:text-2xl">{{ $t('careers.partner_title') }}</h2>
                <p class="mt-1 max-w-xl text-orange-50">{{ $t('careers.partner_line') }}</p>
            </div>
            <a href="{{ route('customer.partners') }}" data-careers-partners class="inline-flex min-h-12 shrink-0 items-center gap-2 rounded-xl bg-white px-5 font-bold text-orange-700 hover:bg-orange-50">
                {{ $t('careers.partner_cta') }}
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
            </a>
        </div>
    </section>

    {{-- ============ How hiring works ============ --}}
    <section class="mx-auto max-w-5xl px-4 pt-12 sm:px-6 lg:px-8">
        <h2 class="text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl">{{ $t('careers.steps_title') }}</h2>
        <ol class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($steps as $s)
                <li class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                    <span class="flex h-10 w-10 items-center justify-center rounded-full {{ $s['bg'] }} text-lg font-extrabold text-white">{{ $s['n'] }}</span>
                    <p class="mt-3 font-bold text-slate-900">{{ $s['title'] }}</p>
                    <p class="mt-1 text-sm text-slate-600">{{ $s['line'] }}</p>
                </li>
            @endforeach
        </ol>
    </section>

    {{-- ============ Any role ============ --}}
    <section class="mx-auto max-w-3xl px-4 py-12 sm:px-6 lg:px-8">
        <div class="mb-5 text-center">
            <h2 class="text-2xl font-extrabold tracking-tight text-slate-900">{{ $t('careers.any_title') }}</h2>
            <p class="mt-1 text-slate-600">{{ $t('careers.any_line') }}</p>
        </div>
        <livewire:careers.apply-form />
    </section>
</div>
</x-layouts.customer>
