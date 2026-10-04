<div @if ($isLive) wire:poll.10s @endif>
    <a href="{{ route('provider.jobs.index') }}" wire:navigate class="text-sm text-slate-500 hover:text-slate-900">← All jobs</a>

    <div class="mt-2 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold tracking-tight">{{ $booking->service?->name ?? 'Service' }}</h1>
            <p class="text-sm text-slate-500">{{ $booking->code }} · {{ str_replace('_', ' ', $booking->status) }}</p>
        </div>
        <p class="text-sm font-semibold">₹{{ number_format((float) ($booking->price_final ?? $booking->price_quoted), 2) }}</p>
    </div>

    @if ($notice)
        <div role="status" class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ $notice }}</div>
    @endif
    @if ($error)
        <div role="alert" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $error }}</div>
    @endif

    @if ($stuckMinutes)
        <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            You've been {{ str_replace('_', ' ', $booking->status) }} on this job for
            {{ $stuckMinutes >= 120 ? floor($stuckMinutes / 60).'h '.($stuckMinutes % 60).'m' : $stuckMinutes.' min' }}.
            {{ in_array($booking->status, ['assigned', 'provider_en_route'], true) ? 'Start it now,' : 'Complete it,' }} or contact your dispatcher if you can't finish it.
        </div>
    @endif

    {{-- ===================== Customer & address ===================== --}}
    <x-ui.card class="mt-4 !p-5">
        <h2 class="text-sm font-semibold text-gray-500 uppercase">Customer</h2>
        <p class="mt-2 text-sm">{{ $booking->customer?->name ?? '—' }}</p>
        @if ($booking->customer?->phone)
            <a href="tel:{{ $booking->customer->phone }}" class="text-sm text-blue-600 hover:underline">{{ $booking->customer->phone }}</a>
        @endif
        <h2 class="mt-4 text-sm font-semibold text-gray-500 uppercase">Address</h2>
        <p class="mt-2 text-sm">{{ $booking->address?->address_line ?? '—' }}</p>
        @if ($booking->address?->landmark)<p class="text-sm text-slate-500">Landmark: {{ $booking->address->landmark }}</p>@endif
        @if ($booking->address && $booking->address->lat && $booking->address->lng)
            <a target="_blank" rel="noopener"
               href="https://maps.google.com/?q={{ $booking->address->lat }},{{ $booking->address->lng }}"
               class="mt-1 inline-flex text-sm text-blue-600 hover:underline">Open in maps</a>
        @endif
    </x-ui.card>

    {{-- ===================== En route ===================== --}}
    @if ($booking->status === 'assigned')
        <x-ui.card class="mt-4 !p-5">
            <h2 class="text-sm font-semibold text-gray-500 uppercase">Heading over?</h2>
            <p class="mt-1 text-sm text-slate-600">Let the customer know you're on the way. Optional — you can go straight to Start.</p>
            <x-ui.button type="button" size="lg" class="mt-3" wire:click="enRoute" wire:loading.attr="disabled" wire:target="enRoute">
                I'm on my way
            </x-ui.button>
        </x-ui.card>
    @endif

    {{-- ===================== At the address / cancel (REF 1CF-CANCEL-POLICY-001) ===================== --}}
    @if (in_array($booking->status, ['assigned', 'provider_en_route'], true))
        <x-ui.card class="mt-4 !p-5" data-testid="provider-cancel-card">
            @if ($booking->status === 'provider_en_route')
                <h2 class="text-sm font-semibold text-gray-500 uppercase">At the address</h2>
                @if (! $arrived)
                    <p class="mt-1 text-sm text-slate-600">Check in when you arrive. Your location is checked against the booking address.</p>
                    <div x-data="{ busy: false }" class="mt-3">
                        <x-ui.button type="button" size="lg" x-bind:disabled="busy"
                            x-on:click="busy = true; navigator.geolocation ? navigator.geolocation.getCurrentPosition(
                                (p) => { $wire.arrive(p.coords.latitude, p.coords.longitude).finally(() => busy = false) },
                                () => { $wire.locationDenied(); busy = false },
                                { enableHighAccuracy: true, timeout: 15000 }) : ($wire.locationDenied(), busy = false)">
                            I've arrived
                        </x-ui.button>
                    </div>
                @else
                    <p class="mt-1 text-sm text-emerald-700">Arrival verified {{ $booking->arrival_verified_at->diffForHumans() }} ({{ $booking->arrival_distance_m }} m from the address).</p>

                    <div class="mt-4 border-t border-slate-200 pt-4">
                        <h3 class="text-sm font-semibold text-slate-800">Quote the customer</h3>
                        <p class="mt-1 text-xs text-slate-500">Quotes must go through the app. A cancellation with the visit charge needs one on record.</p>
                        @if ($latestQuote)
                            <p class="mt-2 text-sm">Last quote: <strong>{{ number_format((float) $latestQuote->amount, 2) }}</strong> — {{ $latestQuote->status }}</p>
                        @endif
                        <form wire:submit="sendQuote" class="mt-2 flex gap-2">
                            <input type="text" inputmode="decimal" wire:model="quoteAmount" placeholder="Amount"
                                   class="min-h-11 w-36 rounded-lg border border-slate-300 px-3 text-base shadow-sm focus:outline focus:outline-2 focus:outline-blue-600">
                            <x-ui.button type="submit" variant="secondary" size="lg" wire:loading.attr="disabled" wire:target="sendQuote">Send quote</x-ui.button>
                        </form>
                        @error('quoteAmount') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>

                    @if ($noShowRule['wait'] !== null && $noShowRule['attempts'] !== null)
                        <div class="mt-4 border-t border-slate-200 pt-4">
                            <h3 class="text-sm font-semibold text-slate-800">Customer not home?</h3>
                            <p class="mt-1 text-xs text-slate-500">Log {{ $noShowRule['attempts'] }} call attempt(s), wait {{ $noShowRule['wait'] }} minute(s) after arriving, then cancel with the visit charge.</p>
                            <p class="mt-1 text-sm">Attempts logged: <strong>{{ $callAttempts }}</strong> / {{ $noShowRule['attempts'] }}</p>
                            <x-ui.button type="button" variant="secondary" class="mt-2" wire:click="logCall" wire:loading.attr="disabled" wire:target="logCall">Log a call attempt</x-ui.button>
                        </div>
                    @endif
                @endif
            @else
                <h2 class="text-sm font-semibold text-gray-500 uppercase">Can't do this job?</h2>
            @endif

            <div class="mt-4 border-t border-slate-200 pt-4">
                <label class="block text-xs font-medium text-slate-600">Note for the record (optional)</label>
                <input type="text" wire:model="cancelNote" maxlength="500" class="mt-1 min-h-11 w-full rounded-lg border border-slate-300 px-3 text-base shadow-sm">
                <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                    @unless ($arrived)
                        <x-ui.button type="button" variant="secondary" wire:click="cancelJob('own_reason')" wire:confirm="Cancel this job for your own reasons? There is no charge to the customer, and your reliability score will drop." wire:loading.attr="disabled" wire:target="cancelJob">Cancel — my own reasons</x-ui.button>
                    @endunless
                    @if ($arrived)
                        <x-ui.button type="button" variant="secondary" wire:click="cancelJob('quote_rejected')" wire:confirm="Cancel because the customer did not accept your quote? The visit charge applies." wire:loading.attr="disabled" wire:target="cancelJob">Cancel — quote not accepted</x-ui.button>
                        @if ($noShowRule['wait'] !== null && $noShowRule['attempts'] !== null)
                            <x-ui.button type="button" variant="secondary" wire:click="cancelJob('customer_unreachable')" wire:confirm="Cancel because the customer is not home / unreachable? The visit charge applies." wire:loading.attr="disabled" wire:target="cancelJob">Cancel — customer not home</x-ui.button>
                        @endif
                    @endif
                </div>
            </div>
        </x-ui.card>
    @endif

    {{-- ===================== OTP step ===================== --}}
    @if (in_array($booking->status, ['assigned', 'provider_en_route'], true))
        <x-ui.card class="mt-4 !p-5">
            <h2 class="text-sm font-semibold text-gray-500 uppercase">Start the job</h2>
            <p class="mt-1 text-sm text-slate-600">Ask the customer for their <strong>start OTP</strong> and enter it.</p>
            <form wire:submit="start" class="mt-3 flex gap-2">
                <input type="text" inputmode="numeric" autocomplete="one-time-code" wire:model="otp"
                       class="min-h-11 w-36 rounded-lg border border-slate-300 px-3 text-base tracking-widest shadow-sm focus:outline focus:outline-2 focus:outline-blue-600">
                <x-ui.button type="submit" size="lg" wire:loading.attr="disabled" wire:target="start">Start</x-ui.button>
            </form>
            @error('otp') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
        </x-ui.card>
    @elseif ($booking->status === 'in_progress')
        <x-ui.card class="mt-4 !p-5">
            <h2 class="text-sm font-semibold text-gray-500 uppercase">Complete the job</h2>
            <p class="mt-1 text-sm text-slate-600">Ask the customer for their <strong>completion OTP</strong> once the work is done.</p>
            <form wire:submit="complete" class="mt-3 flex gap-2">
                <input type="text" inputmode="numeric" autocomplete="one-time-code" wire:model="otp"
                       class="min-h-11 w-36 rounded-lg border border-slate-300 px-3 text-base tracking-widest shadow-sm focus:outline focus:outline-2 focus:outline-blue-600">
                <x-ui.button type="submit" size="lg" wire:loading.attr="disabled" wire:target="complete">Complete</x-ui.button>
            </form>
            @error('otp') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror

            <div class="mt-4 border-t border-slate-200 pt-3">
                <p class="text-sm text-slate-600">Need a part to finish the job?</p>
                @if (! $showSparesForm)
                    <x-ui.button type="button" variant="secondary" class="mt-2" wire:click="$set('showSparesForm', true)">Waiting for spares</x-ui.button>
                @else
                    {{-- REF 1CF-CANCEL-POLICY-001 — mandatory declaration: it is what the customer is charged on if they leave --}}
                    <form wire:submit="holdForSpares" class="mt-3 space-y-2 rounded-lg border border-amber-200 bg-amber-50 p-3">
                        <p class="text-xs text-amber-900">Be accurate: the customer sees these figures and can dispute them. If the part will take longer than {{ app(\App\Services\Cancellation\SparesDelayClock::class)->thresholdDays($booking) }} days the customer may cancel and pay only for work already done.</p>
                        @php($sparesCap = app(\App\Services\Cancellation\InterimChargeCalculator::class)->capValue($booking))
                        <label class="block text-xs font-medium text-slate-700">Amount for the work already done (labour and parts together){{ $sparesCap !== null ? ' — at most ₹'.rtrim(rtrim(number_format($sparesCap, 2), '0'), '.') : '' }}
                            <input type="number" step="0.01" min="0" wire:model="sparesAmount" class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        </label>
                        @error('sparesAmount') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
                        <label class="block text-xs font-medium text-slate-700">Bill / photo of the work or parts (optional)
                            <input type="file" multiple accept="image/*,application/pdf" wire:model="sparesEvidence" class="mt-1 block w-full text-sm">
                        </label>
                        @error('sparesEvidence.*') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
                        <label class="block text-xs font-medium text-slate-700">Who is getting the spare part?
                            <select wire:model="sparesSource" class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                <option value="provider">Me</option>
                                <option value="platform">1CallFix</option>
                                <option value="customer">The customer is supplying it</option>
                            </select>
                        </label>
                        <label class="block text-xs font-medium text-slate-700">Part expected on
                            <input type="date" wire:model="sparesExpected" min="{{ now()->toDateString() }}" class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        </label>
                        @error('sparesExpected') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
                        <div class="flex gap-2">
                            <x-ui.button type="submit" variant="secondary" wire:loading.attr="disabled" wire:target="holdForSpares">Put job on hold</x-ui.button>
                            <x-ui.button type="button" variant="secondary" wire:click="$set('showSparesForm', false)">Cancel</x-ui.button>
                        </div>
                    </form>
                @endif
            </div>

            <form wire:submit="proposeExtraWork" class="mt-4 border-t border-slate-200 pt-3">
                <p class="text-sm text-slate-600">Found extra work the customer must approve?</p>
                <input type="text" wire:model="extraDescription" maxlength="200" placeholder="What needs doing (e.g. gas refill)" class="mt-2 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                @error('extraDescription') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                <input type="number" step="0.01" min="0" wire:model="extraAmount" placeholder="Extra amount" class="mt-2 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                @error('extraAmount') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                <x-ui.button type="submit" variant="secondary" class="mt-2" wire:loading.attr="disabled" wire:target="proposeExtraWork">Ask customer to approve</x-ui.button>
            </form>

            <div class="mt-4 border-t border-slate-200 pt-3">
                <p class="text-sm text-slate-600">Can't finish this job?</p>
                <x-ui.button type="button" variant="secondary" class="mt-2" wire:click="cannotContinue" wire:confirm="Report that you cannot continue? Your dispatcher will hand the job to someone else." wire:loading.attr="disabled" wire:target="cannotContinue">I can't continue</x-ui.button>
            </div>
        </x-ui.card>
    @elseif ($booking->status === 'on_hold')
        <x-ui.card class="mt-4 !p-5">
            <h2 class="text-sm font-semibold text-gray-500 uppercase">Job on hold</h2>
            @if ($booking->hold_reason === 'awaiting_spares')
                <p class="mt-1 text-xs text-slate-500">Declared: {{ $booking->interim_amount !== null ? '₹'.number_format((float) $booking->interim_amount, 2) : '—' }} for work done · expected {{ $booking->spares_expected_at?->format('j M Y') ?? 'no date' }}</p>
                @if ($booking->spares_expected_at && $booking->spares_expected_at->lt(now()->startOfDay()))
                    <form wire:submit="updateExpectedDate" class="mt-2 flex flex-wrap items-end gap-2 rounded-lg border border-red-200 bg-red-50 p-3">
                        <label class="text-xs font-medium text-red-900">The expected date has passed — enter a new date
                            <input type="date" wire:model="newExpectedDate" min="{{ now()->toDateString() }}" class="mt-1 block rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        </label>
                        <x-ui.button type="submit" variant="secondary" wire:loading.attr="disabled" wire:target="updateExpectedDate">Save date</x-ui.button>
                    </form>
                @endif
                @if (! $sparesMarked)
                    <p class="mt-1 text-sm text-slate-600">You are waiting for spare parts. Tap below as soon as you have them.</p>
                    <x-ui.button type="button" size="lg" class="mt-3" wire:click="sparesAvailable" wire:loading.attr="disabled" wire:target="sparesAvailable">Spares available</x-ui.button>
                @else
                    <p class="mt-1 text-sm text-slate-600">Spare parts are with you. Resume the work to continue the job.</p>
                    <x-ui.button type="button" size="lg" class="mt-3" wire:click="resumeJob" wire:loading.attr="disabled" wire:target="resumeJob">Resume work</x-ui.button>
                @endif
            @elseif ($booking->hold_reason === 'awaiting_customer_approval')
                <p class="mt-1 text-sm text-slate-600">Waiting for the customer to approve or decline your extra-work request. The job resumes automatically once they answer.</p>
            @else
                <p class="mt-1 text-sm text-slate-600">This job is on hold. Your dispatcher will be in touch.</p>
            @endif
        </x-ui.card>
    @elseif ($booking->status === 'completed')
        <x-ui.card class="mt-4 !p-5">
            <p class="text-sm font-semibold text-emerald-700">Job completed.</p>
            @if ($commission)
                <p class="mt-1 text-sm text-slate-600">Your earnings: ₹{{ number_format((float) $commission->provider_commission, 2) }} — added to your wallet.</p>
            @endif
        </x-ui.card>
    @endif

    {{-- ===================== Job journey ===================== --}}
    @if ($journey)
        <x-ui.card class="mt-4 !p-5">
            <x-journey.timeline :journey="$journey" :franchise="$booking->franchise" variant="compact" />

            <details class="mt-4 text-sm">
                <summary class="cursor-pointer text-slate-500">Full log</summary>
                <ol class="mt-2 space-y-2">
                    @foreach ($booking->statusHistory as $h)
                        <li class="flex justify-between gap-3">
                            <span>{{ str_replace('_', ' ', $h->status) }}@if ($h->note) — <span class="text-slate-500">{{ $h->note }}</span>@endif</span>
                            <span class="shrink-0 text-xs text-slate-400">{{ app(\App\Services\TimezoneResolver::class)->format($h->changed_at, $booking->franchise, 'j M, g:i A') }}</span>
                        </li>
                    @endforeach
                </ol>
            </details>
        </x-ui.card>
    @endif
</div>
