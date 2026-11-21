@php
    use App\Support\InfoPages\CareersPage;
    $brand = \App\Services\Seo\SeoSettings::siteName();
    $t = fn (string $key, array $r = []) => \App\Support\SiteText::get($key, $r + ['brand' => $brand]);
    $c = CareersPage::colours($job->team);
    $open = $job->isAccepting();
    $sections = [
        ['key' => 'careers.job.duties', 'lines' => $job->duties ?? [], 'dot' => 'bg-blue-500'],
        ['key' => 'careers.job.requirements', 'lines' => $job->requirements ?? [], 'dot' => 'bg-violet-500'],
        ['key' => 'careers.job.perks', 'lines' => $job->perks ?? [], 'dot' => 'bg-emerald-500'],
    ];
@endphp
{{-- One job on the Careers page (owner 2026-11-21) with its application form. A closed job keeps its page. --}}
<x-layouts.customer :title="$job->title.' · '.$job->location.' · '.$brand" :raw-title="true" :meta-description="\Illuminate\Support\Str::limit((string) $job->summary, 155)" :indexable="$open" :schema="$schema">
<div class="mb-bottom-nav pb-24 lg:pb-0" data-careers-job="{{ $job->slug }}" x-data="{ formSeen: false }">

    <section class="relative overflow-hidden bg-gradient-to-br from-slate-900 via-blue-950 to-indigo-900 text-white">
        <div aria-hidden="true" class="pointer-events-none absolute -right-24 -top-24 h-80 w-80 rounded-full bg-blue-400/25 blur-3xl"></div>
        <div class="relative mx-auto max-w-5xl px-4 pb-10 pt-8 sm:px-6 lg:px-8">
            <a href="{{ route('customer.careers') }}" class="inline-flex min-h-11 items-center gap-1.5 text-sm font-semibold text-blue-100 hover:text-white">
                <x-icon name="arrow-left" class="h-4 w-4" /> {{ $t('careers.job.back') }}
            </a>
            <div class="mt-3 flex flex-wrap items-center gap-2 text-xs font-bold">
                @if ($job->team)<span class="rounded-full px-2.5 py-1 {{ $c['badge'] }}">{{ $job->team }}</span>@endif
                <span class="rounded-full bg-white/15 px-2.5 py-1">{{ $job->typeLabel() }}</span>
                <span class="inline-flex items-center gap-1 rounded-full bg-white/15 px-2.5 py-1"><x-icon name="map-pin" class="h-3.5 w-3.5" />{{ $job->location }}</span>
                <span class="rounded-full bg-white/15 px-2.5 py-1">{{ $job->openings === 1 ? $t('careers.openings_one') : $t('careers.openings', ['count' => $job->openings]) }}</span>
                @if ($job->needs_two_wheeler)<span class="rounded-full bg-amber-300 px-2.5 py-1 text-amber-950">🛵 {{ $t('careers.job.two_wheeler') }}</span>@endif
            </div>
            <h1 class="mt-3 text-3xl font-extrabold leading-tight tracking-tight sm:text-4xl">{{ $job->title }}</h1>
            @if ($job->summary)<p class="mt-3 max-w-2xl text-base text-blue-50 sm:text-lg">{{ $job->summary }}</p>@endif
            <div class="mt-5 flex flex-wrap items-center gap-3">
                @if ($job->pay_text)
                    <span class="inline-flex min-h-11 items-center rounded-xl bg-emerald-400 px-4 font-extrabold text-emerald-950"><span class="mr-1.5 text-sm font-bold opacity-80">{{ $t('careers.job.pay') }}</span>{{ $job->pay_text }}</span>
                @endif
                @if ($open)
                    <a href="#apply" class="inline-flex min-h-11 items-center gap-2 rounded-xl bg-amber-400 px-5 font-bold text-slate-900 hover:bg-amber-300">{{ $t('careers.job.apply_now') }}</a>
                @endif
                <x-customer.share-button :title="$job->title" round />
            </div>
            @if ($open && $job->apply_by)
                <p class="mt-3 text-sm font-semibold text-amber-200">{{ $t('careers.job.apply_by', ['date' => $job->apply_by->format('j M Y')]) }}</p>
            @endif
        </div>
    </section>

    @unless ($open)
        <div class="mx-auto max-w-5xl px-4 pt-6 sm:px-6 lg:px-8">
            <p data-job-closed class="rounded-2xl bg-amber-50 p-4 font-semibold text-amber-900 ring-1 ring-amber-200">{{ $t('careers.job.closed') }}</p>
        </div>
    @endunless

    <div class="mx-auto grid max-w-5xl gap-8 px-4 py-8 sm:px-6 lg:grid-cols-5 lg:px-8">
        <div class="space-y-6 lg:col-span-3">
            @foreach ($sections as $s)
                @if (! empty($s['lines']))
                    <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 sm:p-6">
                        <h2 class="text-lg font-extrabold text-slate-900">{{ $t($s['key']) }}</h2>
                        <ul class="mt-3 space-y-2.5">
                            @foreach ($s['lines'] as $line)
                                <li class="flex gap-3 text-slate-700"><span aria-hidden="true" class="mt-2 h-2 w-2 shrink-0 rounded-full {{ $s['dot'] }}"></span><span>{{ $line }}</span></li>
                            @endforeach
                        </ul>
                    </section>
                @endif
            @endforeach
        </div>

        <div class="lg:col-span-2">
            <div class="lg:sticky lg:top-24" x-intersect:enter="formSeen = true" x-intersect:leave="formSeen = false">
                @if ($open)
                    <livewire:careers.apply-form :job-id="$job->id" />
                @else
                    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                        <h2 class="text-lg font-extrabold text-slate-900">{{ $t('careers.jobs_title') }}</h2>
                        @forelse ($otherJobs as $other)
                            <a href="{{ $other->url() }}" class="mt-3 flex min-h-11 items-center justify-between gap-2 rounded-xl bg-slate-50 px-4 py-3 font-semibold text-slate-800 hover:bg-blue-50">
                                {{ $other->title }} <x-icon name="chevron-right" class="h-4 w-4 shrink-0" />
                            </a>
                        @empty
                            <p class="mt-2 text-slate-600">{{ $t('careers.no_jobs') }}</p>
                            <a href="{{ route('customer.careers') }}#apply" class="mt-3 inline-flex min-h-11 items-center font-bold text-blue-700">{{ $t('careers.form.title_any') }}</a>
                        @endforelse
                    </div>
                @endif
            </div>
        </div>
    </div>

    @if ($open)
        {{-- Phones: the Apply button stays at the bottom (ease-of-use rule) until the form itself is on screen. --}}
        <div x-show="! formSeen" x-transition.opacity class="fixed inset-x-0 bottom-16 z-30 border-t border-slate-200 bg-white/95 p-3 backdrop-blur lg:hidden">
            <a href="#apply" class="flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-blue-600 font-bold text-white">{{ $t('careers.job.apply_now') }}</a>
        </div>
    @endif
</div>
</x-layouts.customer>
