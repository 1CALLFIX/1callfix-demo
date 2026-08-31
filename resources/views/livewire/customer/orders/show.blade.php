{{-- Phase E6 — one booking, for its owner. Every action on this page calls
     an existing Action/Service; the OTP codes are display-only. --}}
@php
    $price = (float) ($booking->price_final ?? $booking->price_quoted);
    $timeline = [
        'searching_provider' => 'We started looking for a professional',
        'assigned' => 'A professional was assigned',
        'provider_en_route' => 'Your professional is on the way',
        'in_progress' => 'Work started',
        'completed' => 'Job completed',
        'cancelled' => 'Booking cancelled',
    ];
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
            <p class="text-sm text-slate-500">{{ $booking->code }} · booked {{ $booking->created_at->format('j M Y, g:i A') }}</p>
        </div>
        <x-customer.order-status :status="$booking->status" :paid="$booking->payment_status === 'paid'" />
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

            {{-- ===================== Timeline ===================== --}}
            <section class="rounded-xl border border-slate-200 p-4 sm:p-5">
                <h2 class="text-base font-semibold">Progress</h2>
                <ol class="mt-3 space-y-3">
                    @foreach ($booking->statusHistory as $entry)
                        <li class="flex gap-3 text-sm">
                            <span aria-hidden="true" class="mt-1 h-2 w-2 shrink-0 rounded-full bg-blue-600"></span>
                            <span>
                                <span class="text-slate-900">{{ $timeline[$entry->status] ?? \Illuminate\Support\Str::headline($entry->status) }}</span>
                                <span class="block text-xs text-slate-400">{{ optional($entry->changed_at)->format('j M, g:i A') }}</span>
                            </span>
                        </li>
                    @endforeach
                    @if ($booking->statusHistory->isEmpty())
                        <li class="text-sm text-slate-500">Waiting for the first update…</li>
                    @endif
                </ol>
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
                    <div><dt class="text-slate-400">When</dt><dd class="text-slate-800">{{ $booking->scheduled_at ? $booking->scheduled_at->format('D j M, g:i A') : 'As soon as possible' }}</dd></div>
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

                @unless (in_array($booking->status, ['completed', 'cancelled'], true))
                    @if ($confirmingCancel)
                        <div class="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm">
                            <p class="text-rose-800">Cancel this booking? A cancellation fee may apply.</p>
                            <div class="mt-2 flex gap-2">
                                <button wire:click="cancel" class="rounded-lg bg-rose-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-rose-700">Yes, cancel</button>
                                <button wire:click="$set('confirmingCancel', false)" class="rounded-lg px-3 py-1.5 text-xs text-slate-600 hover:bg-slate-100">Keep it</button>
                            </div>
                        </div>
                    @else
                        <button wire:click="$set('confirmingCancel', true)"
                                class="w-full rounded-lg px-4 py-2.5 text-sm font-medium text-rose-600 hover:bg-rose-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-600">
                            Cancel booking
                        </button>
                    @endif
                @endunless
            </div>
        </aside>
    </div>

    {{-- Razorpay checkout — only wired when the gateway is configured. The
         server already created the pending Payment + order; the webhook
         remains the source of truth for capture. --}}
    @if ($gatewayConfigured)
        <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
        <script>
            document.addEventListener('livewire:init', () => {
                Livewire.on('razorpay-open', (e) => {
                    const o = e.order ?? e[0]?.order;
                    if (!o || !window.Razorpay) return;
                    new window.Razorpay({
                        key: o.razorpay_key_id,
                        order_id: o.razorpay_order_id,
                        amount: o.amount,
                        currency: o.currency,
                        name: @js(\App\Models\Setting::get('branding.platform_name', '1CallFix')),
                        description: 'Booking ' + (e.bookingCode ?? ''),
                        handler: () => window.location.reload(),
                    }).open();
                });
            });
        </script>
    @endif
</div>
