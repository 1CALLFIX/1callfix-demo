@props(['journey', 'franchise' => null, 'variant' => 'full'])
{{-- REF 1CF-JOURNEY-001 — the job span / journey timeline. `journey` comes from
     App\Support\Journey\JourneyBuilder::build(); one component serves the customer, provider and
     admin screens and every module (service, parcel, taxi, food, retail, hotel, rental, property). --}}
@php
    $tz = app(\App\Services\TimezoneResolver::class);
    $fmt = fn ($d) => $d ? $tz->format($d, $franchise, 'j M, g:i A') : null;
    $tones = [
        'blue' => 'from-blue-600 to-indigo-600',
        'green' => 'from-emerald-500 to-teal-600',
        'amber' => 'from-amber-500 to-orange-500',
        'red' => 'from-rose-500 to-red-600',
    ];
    $gradient = $tones[$journey['tone']] ?? $tones['blue'];
    $compact = $variant === 'compact';
    $steps = $journey['steps'];
    $terminal = $journey['terminal'];
@endphp

<section aria-label="{{ $journey['title'] }} progress">
    {{-- Headline + progress --}}
    <div class="rounded-2xl bg-gradient-to-br {{ $gradient }} p-4 text-white shadow-lg">
        <p class="text-[11px] font-semibold uppercase tracking-wider text-white/80">{{ $journey['title'] }}</p>
        <p class="mt-0.5 text-lg font-bold leading-snug">{{ $journey['headline'] }}</p>
        @unless ($compact)
            <p class="mt-0.5 text-sm text-white/90">{{ $journey['hint'] }}</p>
        @endunless
        @if (! empty($journey['chips']))
            <ul class="mt-2.5 flex flex-wrap gap-1.5" aria-label="Booking details">
                @foreach ($journey['chips'] as $chip)
                    <li class="rounded-full bg-white/20 px-2.5 py-0.5 text-xs font-medium text-white ring-1 ring-white/30">{{ $chip['text'] }}</li>
                @endforeach
            </ul>
        @endif
        <div class="mt-3 h-2 overflow-hidden rounded-full bg-white/30" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $journey['progress'] }}" aria-label="Progress">
            <div class="h-full rounded-full bg-white transition-all duration-700" style="width: {{ $journey['progress'] }}%"></div>
        </div>
        <p class="mt-1 text-xs text-white/80">{{ $journey['progress'] }}% complete</p>
    </div>

    {{-- Steps --}}
    <ol class="mt-5">
        @foreach ($steps as $step)
            @php
                $state = $step['state'];
                $isLast = $loop->last && ! $terminal;
            @endphp
            <li class="relative flex gap-4 pb-6 last:pb-0" @if (in_array($state, ['current', 'paused'], true)) aria-current="step" @endif>
                @unless ($isLast)
                    <span aria-hidden="true" class="absolute left-4 top-9 -ml-px h-[calc(100%-2.25rem)] w-0.5 {{ $state === 'done' ? 'bg-emerald-400' : 'bg-slate-200' }}"></span>
                @endunless

                {{-- marker --}}
                <span aria-hidden="true" @class([
                    'relative z-10 grid h-8 w-8 shrink-0 place-items-center rounded-full text-xs font-bold',
                    'bg-emerald-500 text-white shadow shadow-emerald-500/30' => $state === 'done',
                    'bg-blue-600 text-white ring-4 ring-blue-200 animate-pulse' => $state === 'current',
                    'bg-amber-500 text-white ring-4 ring-amber-200' => $state === 'paused',
                    'border-2 border-slate-300 bg-white text-slate-400' => $state === 'upcoming',
                ])>
                    @if ($state === 'done')
                        <svg viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-7.5 7.5a1 1 0 01-1.4 0L3.3 9.7a1 1 0 111.4-1.4l3.8 3.8 6.8-6.8a1 1 0 011.4 0z" clip-rule="evenodd"/></svg>
                    @elseif ($state === 'paused')
                        <svg viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4"><path d="M6 4h3v12H6zM11 4h3v12h-3z"/></svg>
                    @else
                        {{ $loop->iteration }}
                    @endif
                </span>

                <div class="min-w-0 flex-1 pt-0.5">
                    <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                        <p @class(['font-semibold', 'text-slate-900' => $state !== 'upcoming', 'text-slate-400' => $state === 'upcoming'])>{{ $step['label'] }}</p>
                        @if ($step['at'])
                            <p class="text-xs text-slate-400">{{ $fmt($step['at']) }}</p>
                        @endif
                    </div>
                    @unless ($compact)
                        <p @class(['text-sm', 'text-slate-500' => $state !== 'upcoming', 'text-slate-300' => $state === 'upcoming'])>
                            {{ $state === 'paused' ? 'Paused for now — details below.' : $step['hint'] }}
                        </p>
                    @endunless

                    {{-- hold / spares episodes branch off the "in progress" step --}}
                    @foreach ($step['episodes'] as $ep)
                        <div class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3">
                            <p class="text-xs font-semibold uppercase tracking-wide text-amber-800">On hold · {{ $ep['label'] }}</p>
                            <ol class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1.5 text-xs">
                                @foreach ($ep['mini'] as $m)
                                    <li class="inline-flex items-center gap-1.5">
                                        <span @class([
                                            'inline-flex items-center gap-1 rounded-full px-2.5 py-1 font-medium',
                                            'bg-emerald-100 text-emerald-800' => $m['state'] === 'done',
                                            'bg-amber-500 text-white' => $m['state'] === 'current',
                                            'bg-white text-slate-400 ring-1 ring-slate-200' => in_array($m['state'], ['upcoming', 'skipped'], true),
                                        ])>
                                            {{ $m['label'] }}@if ($m['at']) <span class="opacity-70">· {{ $fmt($m['at']) }}</span>@endif
                                        </span>
                                        @unless ($loop->last)<span aria-hidden="true" class="text-amber-400">→</span>@endunless
                                    </li>
                                @endforeach
                            </ol>
                        </div>
                    @endforeach
                </div>
            </li>
        @endforeach

        @if ($terminal)
            <li class="relative flex gap-4 pt-1">
                <span aria-hidden="true" @class([
                    'relative z-10 grid h-8 w-8 shrink-0 place-items-center rounded-full text-white',
                    'bg-red-500' => $terminal['tone'] === 'red',
                    'bg-amber-500' => $terminal['tone'] !== 'red',
                ])>
                    <svg viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4"><path d="M6.3 5l3.7 3.7L13.7 5 15 6.3 11.3 10l3.7 3.7-1.3 1.3L10 11.3 6.3 15 5 13.7 8.7 10 5 6.3z"/></svg>
                </span>
                <div class="min-w-0 flex-1 pt-0.5">
                    <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                        <p class="font-semibold text-slate-900">{{ $terminal['label'] }}</p>
                        @if ($terminal['at'])<p class="text-xs text-slate-400">{{ $fmt($terminal['at']) }}</p>@endif
                    </div>
                    @unless ($compact)<p class="text-sm text-slate-500">{{ $terminal['hint'] }}</p>@endunless
                    @foreach ($terminal['details'] ?? [] as $line)
                        <p class="text-sm text-slate-600">{{ $line }}</p>
                    @endforeach
                </div>
            </li>
        @endif
    </ol>
</section>
