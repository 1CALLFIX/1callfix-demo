{{-- Phase E6 — one booking, for its owner. Every action on this page calls
     an existing Action/Service; the OTP codes are display-only. --}}
@php
    $price = (float) ($booking->price_final ?? $booking->price_quoted);
@endphp

{{-- While the booking is still in flight (dispatch running, or the job
     under way) the component re-polls itself every few seconds, so
     "Finding a professional" -> "assigned" -> "on the way" -> "completed"
     updates without the customer refreshing. It stops the moment the
     booking reaches a terminal state — a completed/cancelled booking never
     changes again. This is real server state each time, not a simulated
     progression; when a WebSocket broadcaster is added later the poll can
     be swapped for an Echo listener with no change to this component. --}}
<div class="mx-auto max-w-3xl px-4 py-6 sm:px-6 lg:px-8" @if ($isInFlight) wire:poll.6s @endif>

    <a href="{{ route('customer.orders.index') }}" wire:navigate
       class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
        <x-icon name="arrow-left" class="h-4 w-4" /> All bookings
    </a>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">{{ $booking->service?->name ?? 'Service' }}</h1>
            <p class="text-sm text-slate-500">{{ $booking->code }} · booked {{ app(\App\Services\TimezoneResolver::class)->format($booking->created_at, $booking->franchise, 'j M Y, g:i A') }}</p>
        </div>
        <x-customer.order-status :status="$booking->status" :paid="$booking->payment_status === 'paid'" :cash="$booking->payment_method === 'cash'" />
    </div>

    @if ($notice)
        <div role="status" class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ $notice }}</div>
    @endif
    @if ($error)
        <div role="alert" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $error }}</div>
    @endif

    {{-- ===================== Finding a professional =====================
         Shown while dispatch is still hunting (pending / searching_provider).
         The pulsing dot is decorative and motion-safe only; the words carry
         the state on their own. `contactedCount` is a real count of distinct
         professionals offered this booking — omitted at zero rather than
         shown as "0". --}}
    @if ($isSearching)
        <section aria-live="polite"
                 class="mt-4 overflow-hidden rounded-xl border border-blue-200 bg-blue-50/70 p-4 sm:p-5">
            <div class="flex items-start gap-3">
                <span aria-hidden="true" class="relative mt-1 grid h-8 w-8 shrink-0 place-items-center">
                    <span class="absolute inline-flex h-full w-full rounded-full bg-blue-400/40 motion-safe:animate-ping"></span>
                    <span class="relative inline-flex h-3 w-3 rounded-full bg-blue-600"></span>
                </span>
                <div class="min-w-0">
                    <h2 class="text-base font-semibold text-slate-900">Finding you a professional</h2>
                    <p class="mt-1 text-sm text-slate-600">
                        We're contacting professionals near
                        {{ $booking->address?->label ? '“'.$booking->address->label.'”' : 'you' }}.
                        This usually takes a few minutes.
                        @if ($contactedCount > 0)
                            <span class="block">{{ $contactedCount }} {{ \Illuminate\Support\Str::plural('professional', $contactedCount) }} contacted so far.</span>
                        @endif
                    </p>
                    <p class="mt-2 text-xs text-slate-500">
                        You can leave this page — your booking is saved and we'll keep looking.
                        This screen updates on its own.
                    </p>
                </div>
            </div>
        </section>
    @elseif ($isInFlight)
        {{-- Past the search, still live: a quieter "updates automatically"
             hint so the customer knows the status will move on its own. --}}
        <p class="mt-4 flex items-center gap-1.5 text-xs text-slate-500">
            <span aria-hidden="true" class="h-1.5 w-1.5 rounded-full bg-emerald-500 motion-safe:animate-pulse"></span>
            This screen updates automatically.
        </p>
    @endif

    <div class="mt-5 grid gap-5 lg:grid-cols-[1fr_16rem]">
        <div class="min-w-0 space-y-5">

            {{-- ===================== Extra work needs approval (REF 1CF-EXTRAWORK-001) ===================== --}}
            @if ($pendingExtra)
                <section class="rounded-2xl border border-amber-300 bg-amber-50 p-4 sm:p-5" aria-live="polite">
                    <h2 class="text-sm font-semibold text-amber-900">Approval needed: extra work</h2>
                    <p class="mt-1 text-sm text-amber-900">Your professional found extra work: <strong>{{ $pendingExtra->description }}</strong> &mdash; <strong>{{ $currencySymbol }}{{ number_format((float) $pendingExtra->amount, 2) }}</strong>. The job is paused until you answer.</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button" wire:click="respondToExtraWork({{ $pendingExtra->id }}, true)" wire:loading.attr="disabled" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Approve</button>
                        <button type="button" wire:click="respondToExtraWork({{ $pendingExtra->id }}, false)" wire:loading.attr="disabled" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">Decline</button>
                    </div>
                </section>
            @endif

            {{-- ===================== Waiting for spares (REF 1CF-CANCEL-POLICY-001) ===================== --}}
            @if ($booking->status === 'on_hold' && $booking->hold_reason === 'awaiting_spares')
                <section class="rounded-2xl border border-amber-300 bg-amber-50 p-4 sm:p-5">
                    <h2 class="text-sm font-semibold text-amber-900">Waiting for spare parts</h2>
                    @if ($booking->interim_declared_at)
                        <dl class="mt-2 grid gap-1 text-sm text-amber-900 sm:grid-cols-3">
                            <div><dt class="text-xs text-amber-700">Work done so far</dt><dd class="font-semibold">{{ $booking->interim_progress_percent }}%</dd></div>
                            <div><dt class="text-xs text-amber-700">Parts already fitted</dt><dd class="font-semibold">{{ $currencySymbol }}{{ number_format((float) $booking->interim_parts_cost, 2) }}</dd></div>
                            <div><dt class="text-xs text-amber-700">Part expected</dt><dd class="font-semibold">{{ $booking->spares_expected_at?->format('j M Y') ?? 'Not given' }}</dd></div>
                        </dl>
                    @endif
                    <p class="mt-2 text-sm text-amber-900">{{ $cancelQuote['message'] }}</p>
                    @if ($booking->interim_dispute_status === 'open')
                        <p class="mt-2 text-sm font-medium text-amber-900">You disputed these figures. Our team is reviewing them; cancellation waits for that review.</p>
                    @elseif ($canDisputeProgress)
                        @if ($disputing)
                            <div class="mt-3 space-y-2">
                                <textarea wire:model="disputeNote" rows="3" maxlength="1000" placeholder="What looks wrong?" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
                                <div class="flex gap-2">
                                    <button type="button" wire:click="disputeProgress" wire:loading.attr="disabled" class="rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-700">Send dispute</button>
                                    <button type="button" wire:click="$set('disputing', false)" class="rounded-lg px-3 py-1.5 text-xs text-slate-600 hover:bg-white">Never mind</button>
                                </div>
                            </div>
                        @else
                            <button type="button" wire:click="$set('disputing', true)" class="mt-3 rounded-lg border border-amber-400 bg-white px-3 py-1.5 text-xs font-semibold text-amber-900 hover:bg-amber-100">Dispute these figures</button>
                        @endif
                    @endif
                    <x-cancellation-policy :lines="$policyLines" class="mt-3" />
                </section>
            @endif

            {{-- ===================== Professional's quote (REF 1CF-CANCEL-POLICY-001) ===================== --}}
            @if ($pendingQuote)
                <section class="rounded-2xl border border-blue-300 bg-blue-50 p-4 sm:p-5" aria-live="polite" data-testid="pending-quote">
                    <h2 class="text-sm font-semibold text-blue-900">Quote from your professional</h2>
                    <p class="mt-1 text-sm text-blue-900"><strong>{{ $currencySymbol }}{{ number_format((float) $pendingQuote->amount, 2) }}</strong> for this job. If you accept and the work is carried out, there is no visit or inspection charge.</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button" wire:click="respondToQuote({{ $pendingQuote->id }}, true)" wire:loading.attr="disabled" class="min-h-11 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">Accept quote</button>
                        <button type="button" wire:click="respondToQuote({{ $pendingQuote->id }}, false)" wire:loading.attr="disabled" class="min-h-11 rounded-lg border border-blue-300 bg-white px-4 py-2 text-sm font-semibold text-blue-900 hover:bg-blue-100">Decline</button>
                    </div>
                </section>
            @endif

            {{-- ===================== Cancellation charge waiting for payment ===================== --}}
            @if ($pendingCancelRequest)
                <section class="rounded-2xl border border-rose-300 bg-rose-50 p-4 sm:p-5" aria-live="polite">
                    <h2 class="text-sm font-semibold text-rose-900">Cancellation charge to pay</h2>
                    @if ($pendingCancelRequest->status === 'awaiting_admin')
                        <p class="mt-1 text-sm text-rose-900">Your cancellation request ({{ $currencySymbol }}{{ number_format((float) $pendingCancelRequest->total_charge, 2) }}) is with our team for review.</p>
                    @else
                        <p class="mt-1 text-sm text-rose-900">Pay <strong>{{ $currencySymbol }}{{ number_format((float) $pendingCancelRequest->total_charge, 2) }}</strong> to settle the cancellation charge.@if ($booking->status !== 'cancelled') The booking stays as it is until then.@endif</p>
                        @if ($gatewayConfigured)
                            <button type="button" wire:click="payCancellationCharge" wire:loading.attr="disabled" class="mt-3 rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700">{{ $booking->status === 'cancelled' ? 'Pay charge' : 'Pay and cancel' }}</button>
                        @endif
                    @endif
                </section>
            @endif

            {{-- ===================== OTP codes (display only) ===================== --}}
            @if ($showStartOtp || $showCompletionOtp)
                <section class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-blue-700 via-blue-600 to-blue-800 p-4 text-white shadow-xl shadow-blue-900/20 sm:p-5">
                    <div aria-hidden="true" class="pointer-events-none absolute -right-10 -top-14 h-44 w-44 rounded-full bg-white/10 blur-3xl"></div>
                    <h2 class="relative text-sm font-semibold uppercase tracking-wide text-blue-100">Your verification codes</h2>
                    <p class="relative mt-1 text-sm text-blue-100">Read these to your professional — never type them in yourself.</p>
                    <div class="relative mt-3 grid gap-3 sm:grid-cols-2">
                        @if ($showStartOtp)
                            <div class="rounded-xl bg-white/10 p-3 ring-1 ring-inset ring-white/15">
                                <p class="text-xs text-blue-100">When they arrive</p>
                                <p class="mt-0.5 font-mono text-2xl font-bold tracking-[0.3em]">{{ $booking->start_otp }}</p>
                            </div>
                        @endif
                        @if ($showCompletionOtp)
                            <div class="rounded-xl bg-white/10 p-3 ring-1 ring-inset ring-white/15">
                                <p class="text-xs text-blue-100">When the job is done</p>
                                <p class="mt-0.5 font-mono text-2xl font-bold tracking-[0.3em]">{{ $booking->completion_otp }}</p>
                            </div>
                        @endif
                    </div>
                </section>
            @endif

            {{-- ===================== Professional ===================== --}}
            @if ($booking->provider?->user)
                <section class="rounded-xl border border-slate-200 p-4 sm:p-5">
                    <h2 class="text-base font-semibold">Your professional</h2>
                    <div class="mt-3 flex items-center gap-3">
                        <span class="grid h-11 w-11 place-items-center rounded-full bg-slate-100 text-sm font-semibold text-slate-700">
                            {{ \Illuminate\Support\Str::of($booking->provider->user->name)->substr(0, 1)->upper() }}
                        </span>
                        <div class="min-w-0">
                            <p class="font-medium text-slate-900">{{ $booking->provider->user->name }}</p>
                            <p class="text-xs text-slate-500">
                                @if ($booking->provider->rating_avg)
                                    ★ {{ number_format((float) $booking->provider->rating_avg, 1) }}
                                @endif
                                @if ($providerDistanceKm !== null && in_array($booking->status, ['assigned', 'provider_en_route'], true))
                                    @if ($booking->provider->rating_avg) <span aria-hidden="true">·</span> @endif
                                    <span>≈ {{ rtrim(rtrim(number_format($providerDistanceKm, 1), '0'), '.') }} km away when assigned</span>
                                @endif
                            </p>
                        </div>
                        @if ($booking->provider->user->phone && in_array($booking->status, ['assigned','provider_en_route','in_progress'], true))
                            <a href="tel:{{ $booking->provider->user->phone }}"
                               class="ml-auto inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                                Call
                            </a>
                        @endif
                    </div>
                </section>
            @endif

            {{-- ===================== Job journey (REF 1CF-JOURNEY-001) ===================== --}}
            <section class="rounded-xl border border-slate-200 p-4 sm:p-5">
                <h2 class="sr-only">Progress</h2>
                @php($journey = \App\Support\Journey\JourneyBuilder::build('service', $booking->status, $booking->statusHistory, \App\Support\Journey\JourneyContext::forBooking($booking)))
                @if ($journey)
                    <x-journey.timeline :journey="$journey" :franchise="$booking->franchise" />
                @endif
            </section>

            {{-- ===================== Payment ===================== --}}
            <section class="rounded-xl border border-slate-200 p-4 sm:p-5">
                <h2 class="text-base font-semibold">Payment</h2>
                <dl class="mt-3 space-y-1.5 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-600">Method</dt><dd class="capitalize">{{ $booking->payment_method }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-600">Status</dt><dd class="capitalize">{{ str_replace('_', ' ', $booking->payment_status) }}</dd></div>
                    <div class="flex justify-between border-t border-slate-200 pt-2 font-semibold">
                        <dt>{{ $booking->price_final !== null ? 'Final total' : 'Quoted total' }}</dt>
                        <dd>{{ $currencySymbol }}{{ number_format($price, 2) }}</dd>
                    </div>
                </dl>

                @if ($booking->payment_status !== 'paid' && $booking->payment_method === 'online' && ! in_array($booking->status, ['cancelled'], true))
                    <div class="mt-3">
                        @if ($gatewayConfigured)
                            <button wire:click="startPayment"
                                    class="inline-flex min-h-11 items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm shadow-blue-600/25 hover:bg-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
                                Pay {{ $currencySymbol }}{{ number_format((float) $booking->price_quoted, 2) }} now
                            </button>
                        @else
                            <p class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
                                Online payment isn't configured in this environment. Our team can take payment over the phone, or pay the professional directly.
                            </p>
                        @endif
                    </div>
                @endif

                @if ($capturedPaymentId)
                    <a href="{{ route('customer.orders.invoice', $booking) }}" target="_blank" rel="noopener"
                       class="mt-3 inline-flex items-center gap-1.5 text-sm font-medium text-slate-700 underline hover:text-slate-900">
                        <x-icon name="document-text" class="h-4 w-4" /> Download receipt (PDF)
                    </a>
                @endif
                @if ($booking->status === 'cancelled')
                    @if ($documents->chargeCollected($booking))
                        <a href="{{ route('customer.orders.cancellation-invoice', $booking) }}" target="_blank" rel="noopener" class="mt-3 flex items-center gap-1.5 text-sm font-medium text-slate-700 underline hover:text-slate-900">
                            <x-icon name="document-text" class="h-4 w-4" /> Download cancellation invoice (PDF)
                        </a>
                    @endif
                    @if ($documents->refundedPayment($booking))
                        <a href="{{ route('customer.orders.credit-note', $booking) }}" target="_blank" rel="noopener" class="mt-3 flex items-center gap-1.5 text-sm font-medium text-slate-700 underline hover:text-slate-900">
                            <x-icon name="document-text" class="h-4 w-4" /> Download credit note (PDF)
                        </a>
                    @endif
                @endif
            </section>

            {{-- ===================== Review ===================== --}}
            @if ($booking->status === 'completed')
                <section class="rounded-xl border border-slate-200 p-4 sm:p-5">
                    <h2 class="text-base font-semibold">Rate your experience</h2>
                    @if ($existingReview)
                        <div class="mt-2 text-sm">
                            <p class="text-amber-500">
                                @for ($i = 1; $i <= 5; $i++){{ $i <= $existingReview->rating ? '★' : '☆' }}@endfor
                            </p>
                            @if ($existingReview->comment)<p class="mt-1 text-slate-600">"{{ $existingReview->comment }}"</p>@endif
                            @if ($existingReview->provider_reply)
                                <p class="mt-2 rounded-lg bg-slate-50 p-2 text-slate-600"><span class="font-medium">Reply:</span> {{ $existingReview->provider_reply }}</p>
                            @endif
                        </div>
                    @else
                        <div class="mt-2">
                            <div class="flex gap-1" role="radiogroup" aria-label="Star rating">
                                @for ($i = 1; $i <= 5; $i++)
                                    <button type="button" wire:click="$set('rating', {{ $i }})"
                                            aria-checked="{{ $rating >= $i ? 'true' : 'false' }}" role="radio"
                                            aria-label="{{ $i }} star{{ $i > 1 ? 's' : '' }}"
                                            @class([
                                                'text-2xl leading-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900',
                                                'text-amber-500' => $rating >= $i,
                                                'text-slate-300' => $rating < $i,
                                            ])>★</button>
                                @endfor
                            </div>
                            @error('rating') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            <textarea wire:model="comment" rows="3" maxlength="2000" placeholder="Tell others how it went (optional)"
                                      class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-600"></textarea>
                            <button wire:click="submitReview"
                                    class="mt-2 inline-flex min-h-11 items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm shadow-blue-600/25 hover:bg-blue-700">
                                Submit review
                            </button>
                        </div>
                    @endif
                </section>
            @endif
        </div>

        {{-- Sidebar --}}
        <aside class="space-y-3 lg:sticky lg:top-20 lg:self-start">
            <div class="rounded-xl border border-slate-200 p-4 text-sm">
                <p class="font-semibold text-slate-900">Details</p>
                <dl class="mt-2 space-y-1.5 text-slate-600">
                    <div><dt class="text-slate-400">When</dt><dd class="text-slate-800">{{ $booking->scheduled_at ? app(\App\Services\TimezoneResolver::class)->format($booking->scheduled_at, $booking->franchise, 'D j M, g:i A') : 'As soon as possible' }}</dd></div>
                    @if ($booking->address)
                        <div><dt class="text-slate-400">Where</dt><dd class="text-slate-800">{{ $booking->address->label }} — {{ $booking->address->address_line }}</dd></div>
                    @endif
                    @if ($booking->customer_note)
                        <div><dt class="text-slate-400">Your note</dt><dd class="text-slate-800">{{ $booking->customer_note }}</dd></div>
                    @endif
                </dl>
            </div>

            <div class="space-y-2">
                @if ($booking->service)
                    <a href="{{ route('customer.book', $booking->service) }}" wire:navigate
                       class="flex w-full items-center justify-center gap-1.5 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-800 hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                        <x-icon name="arrow-path" class="h-4 w-4" /> Book this again
                    </a>
                @endif

                @if ($booking->status === 'in_progress')
                    <button type="button" wire:click="reportProfessionalLeft" wire:confirm="Tell us your professional left before finishing the work?"
                            class="w-full rounded-lg border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm font-semibold text-amber-900 hover:bg-amber-100">
                        My professional left
                    </button>
                @endif

                @unless (in_array($booking->status, ['completed', 'cancelled'], true))
                    <x-cancellation-policy :lines="$policyLines" :booking="$booking" class="mb-3" />
                    @if ($confirmingCancel)
                        <div class="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm">
                            @if (! $cancelQuote['allowed'])
                                <p class="text-rose-800">{{ $cancelQuote['message'] }}</p>
                                <button wire:click="$set('confirmingCancel', false)" class="mt-2 rounded-lg px-3 py-1.5 text-xs text-slate-600 hover:bg-slate-100">Close</button>
                            @else
                                <p class="text-rose-800">{{ $cancelQuote['message'] }}</p>
                                @if (! empty($cancelQuote['breakdown']['display']))
                                    <p class="mt-1 text-xs text-rose-700" data-testid="visit-charge-display">{{ $cancelQuote['breakdown']['display'] }}</p>
                                @endif
                                @if ($cancelQuote['charge'] > 0)
                                    <dl class="mt-2 space-y-1 text-rose-900">
                                        <div class="flex justify-between"><dt>Charge</dt><dd class="font-semibold">{{ $currencySymbol }}{{ number_format($cancelQuote['charge'], 2) }}</dd></div>
                                        @if (! empty($cancelQuote['breakdown']['cap_percent']))
                                            <div class="flex justify-between text-xs text-rose-700"><dt>{{ ! empty($cancelQuote['breakdown']['min_labour_applied']) ? 'Minimum labour charge' : 'Labour ('.($cancelQuote['breakdown']['progress_percent'] ?? 0).'% done, max '.$cancelQuote['breakdown']['cap_percent'].'%)' }}</dt><dd>{{ $currencySymbol }}{{ number_format($cancelQuote['breakdown']['labour_charge'], 2) }}</dd></div>
                                            <div class="flex justify-between text-xs text-rose-700"><dt>Parts fitted</dt><dd>{{ $currencySymbol }}{{ number_format($cancelQuote['breakdown']['parts_charge'], 2) }}</dd></div>
                                        @endif
                                        @if ($cancelQuote['refund'] > 0)
                                            <div class="flex justify-between"><dt>Refunded to you</dt><dd class="font-semibold">{{ $currencySymbol }}{{ number_format($cancelQuote['refund'], 2) }}</dd></div>
                                        @endif
                                    </dl>
                                    @if ($cancelQuote['requires_payment'])
                                        <p class="mt-2 text-xs text-rose-700">You pay this charge first; the booking is cancelled as soon as it is paid.</p>
                                    @endif
                                @endif
                                <div class="mt-2 flex gap-2">
                                    <button wire:click="cancel" wire:loading.attr="disabled" class="rounded-lg bg-rose-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-rose-700">{{ $cancelQuote['requires_payment'] ? 'Pay and cancel' : 'Yes, cancel' }}</button>
                                    <button wire:click="$set('confirmingCancel', false)" class="rounded-lg px-3 py-1.5 text-xs text-slate-600 hover:bg-slate-100">Keep it</button>
                                </div>
                            @endif
                        </div>
                    @else
                        <button wire:click="openCancel"
                                class="w-full rounded-lg px-4 py-2.5 text-sm font-medium text-rose-600 hover:bg-rose-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-600">
                            Cancel booking
                        </button>
                        @if (! $cancelQuote['allowed'] && $booking->status !== 'on_hold')
                            <p class="px-1 text-xs text-slate-500">{{ $cancelQuote['message'] }}</p>
                        @endif
                    @endif
                @endunless
            </div>
        </aside>
    </div>

    {{-- Razorpay checkout — only wired when the gateway is configured. The
         server already created the pending Payment + order; the webhook
         remains the source of truth for capture. --}}
    {{-- @script, not a 'livewire:init' listener: the wizard lands here via
         wire:navigate, after which 'livewire:init' never fires again. --}}
    @if ($gatewayConfigured)
        @assets
        <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
        @endassets
        @script
        <script>
            $wire.on('razorpay-open', (e) => {
                const o = e.order ?? e[0]?.order;
                if (!o || !window.Razorpay) return;
                new window.Razorpay({
                    key: o.razorpay_key_id ?? o.key_id,
                    order_id: o.razorpay_order_id,
                    amount: o.amount,
                    currency: o.currency,
                    name: @js(\App\Models\Setting::get('branding.platform_name', '1CallFix')),
                    description: 'Booking ' + (e.bookingCode ?? ''),
                    handler: () => window.location.reload(),
                }).open();
            });
        </script>
        @endscript
    @endif
</div>
