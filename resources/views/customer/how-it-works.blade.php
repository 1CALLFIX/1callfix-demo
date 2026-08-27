@php
    $platformName = \App\Models\Setting::get('branding.platform_name', '1CallFix');

    /*
     | Describes only mechanics that genuinely exist in this platform today
     | and are verifiable in the codebase: zone-scoped dispatch to eligible
     | providers, server-side pricing, and the start/completion one-time
     | codes the job lifecycle actually enforces. No guarantee, warranty,
     | timeframe or refund promise is stated here — those would be new
     | commercial claims, and this page is not the place to invent them.
     */
    $steps = [
        [
            'title' => 'Tell us what you need',
            'body' => 'Choose the service you need and the area you need it in. Prices are set by us and shown up front.',
        ],
        [
            'title' => 'We find an available professional',
            'body' => 'Your job is offered to verified professionals working in your area who handle that kind of work.',
        ],
        [
            'title' => 'Your professional arrives',
            'body' => 'You will see who is coming. The job only starts once you share your one-time start code with them.',
        ],
        [
            'title' => 'The work gets done',
            'body' => 'When the work is finished you share your completion code, which closes the job on our side.',
        ],
        [
            'title' => 'Pay and rate',
            'body' => 'Settle up through the platform, then rate the work so other customers know what to expect.',
        ],
    ];
@endphp

<x-layouts.customer title="How it works">
    <div class="mx-auto max-w-3xl px-4 py-12 sm:px-6 sm:py-16 lg:px-8">
        <header>
            <h1 class="text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">
                How {{ $platformName }} works
            </h1>
            <p class="mt-3 text-base text-slate-600">
                From the moment you tell us what you need to the moment the job is signed off.
            </p>
        </header>

        <ol class="mt-12 space-y-10">
            @foreach ($steps as $index => $step)
                <li class="flex gap-5">
                    <span aria-hidden="true"
                          class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-slate-900 text-sm font-bold text-white">
                        {{ $index + 1 }}
                    </span>
                    <div>
                        <h2 class="text-lg font-semibold text-slate-900">
                            <span class="sr-only">Step {{ $index + 1 }}: </span>{{ $step['title'] }}
                        </h2>
                        <p class="mt-1.5 text-sm leading-relaxed text-slate-600">{{ $step['body'] }}</p>
                    </div>
                </li>
            @endforeach
        </ol>

        <div class="mt-14 rounded-xl border border-slate-200 bg-slate-50 p-6">
            <h2 class="text-base font-semibold text-slate-900">About your one-time codes</h2>
            <p class="mt-1.5 text-sm leading-relaxed text-slate-600">
                Your start and completion codes are how you confirm that the right professional turned up and that the
                work is genuinely finished. Share them with your professional only — never with anyone else, and never
                before the work they cover has actually happened.
            </p>
        </div>

        <p class="mt-8 text-sm text-slate-600">
            Questions we have not covered here are in the
            <a href="{{ route('customer.help') }}"
               class="font-medium text-slate-900 underline underline-offset-4 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900 rounded">help centre</a>.
        </p>
    </div>
</x-layouts.customer>
