@php
    $platformName = \App\Models\Setting::get('branding.platform_name', '1CallFix');
@endphp

<div class="mx-auto flex max-w-md flex-col justify-center px-4 py-12 sm:px-6 sm:py-20">

    <div class="text-center">
        <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">
            {{ $step === 'phone' ? 'Sign in to '.$platformName : 'Enter your code' }}
        </h1>
        <p class="mt-2 text-sm text-slate-600">
            @if ($step === 'phone')
                We'll text you a verification code. No password needed.
            @else
                Sent to <span class="font-medium text-slate-900">{{ $phone }}</span>.
            @endif
        </p>
    </div>

    <x-ui.card class="mt-8 !p-6 sm:!p-8">

        {{-- Errors and confirmations are announced to screen readers as they
             appear, and are never colour-only — each carries an icon glyph
             and explicit text (WCAG 2.1 AA 1.4.1 / 3.3.1). --}}
        @if ($error)
            <div role="alert"
                 class="mb-5 flex items-start gap-2 rounded-lg bg-red-50 px-3 py-2.5 text-sm text-red-800">
                <span aria-hidden="true" class="mt-px font-bold">!</span>
                <span>{{ $error }}</span>
            </div>
        @endif

        @if ($status)
            <div role="status"
                 class="mb-5 flex items-start gap-2 rounded-lg bg-emerald-50 px-3 py-2.5 text-sm text-emerald-800">
                <span aria-hidden="true" class="mt-px font-bold">&check;</span>
                <span>{{ $status }}</span>
            </div>
        @endif

        @if ($step === 'phone')
            <form wire:submit="requestCode" class="space-y-5">
                <div>
                    <label for="customer-phone" class="block text-sm font-medium text-slate-900">
                        Mobile number
                    </label>
                    <input id="customer-phone"
                           type="tel"
                           inputmode="tel"
                           autocomplete="tel"
                           autofocus
                           wire:model="phone"
                           placeholder="e.g. 9876543210"
                           @class(['mt-1.5 block min-h-11 w-full rounded-lg border px-3 py-2.5 text-base shadow-sm transition focus:outline focus:outline-2 focus:outline-offset-0 focus:outline-slate-900',
                                   'border-red-400' => $errors->has('phone'),
                                   'border-slate-300' => ! $errors->has('phone')])
                           @if ($errors->has('phone')) aria-invalid="true" aria-describedby="customer-phone-error" @endif>
                    @error('phone')
                        <p id="customer-phone-error" class="mt-1.5 text-sm text-red-700">{{ $message }}</p>
                    @enderror
                </div>

                <x-ui.button type="submit" size="lg" class="w-full !min-h-11" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="requestCode">Send verification code</span>
                    <span wire:loading wire:target="requestCode">Sending…</span>
                </x-ui.button>
            </form>
        @else
            <form wire:submit="verifyCode" class="space-y-5">
                <div>
                    <label for="customer-otp" class="block text-sm font-medium text-slate-900">
                        Verification code
                    </label>
                    {{-- autocomplete="one-time-code" lets iOS/Android offer the
                         code straight from the SMS; inputmode="numeric" brings
                         up the digit keypad rather than the full keyboard. --}}
                    <input id="customer-otp"
                           type="text"
                           inputmode="numeric"
                           autocomplete="one-time-code"
                           autofocus
                           maxlength="8"
                           wire:model="code"
                           @class(['mt-1.5 block min-h-11 w-full rounded-lg border px-3 py-2.5 text-center text-2xl font-semibold tracking-[0.4em] shadow-sm transition focus:outline focus:outline-2 focus:outline-offset-0 focus:outline-slate-900',
                                   'border-red-400' => $errors->has('code'),
                                   'border-slate-300' => ! $errors->has('code')])
                           @if ($errors->has('code')) aria-invalid="true" aria-describedby="customer-otp-error" @endif>
                    @error('code')
                        <p id="customer-otp-error" class="mt-1.5 text-sm text-red-700">{{ $message }}</p>
                    @enderror
                </div>

                <x-ui.button type="submit" size="lg" class="w-full !min-h-11" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="verifyCode">Verify and continue</span>
                    <span wire:loading wire:target="verifyCode">Verifying…</span>
                </x-ui.button>

                <div class="flex items-center justify-between gap-3 pt-1">
                    <button type="button"
                            wire:click="changePhone"
                            class="min-h-11 rounded text-sm font-medium text-slate-600 underline-offset-4 transition hover:text-slate-900 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                        Change number
                    </button>

                    {{-- Countdown is presentational only. The authoritative
                         cooldown is enforced by OtpService::generate(), which
                         throws if called too soon regardless of what the
                         browser's timer believes — a customer with JS
                         disabled simply gets that message instead of a
                         greyed-out button, never an unthrottled resend.

                         Plain JS rather than Alpine, matching the convention
                         the rest of this codebase already follows (see
                         layouts/admin.blade.php's sidebar scripts and the
                         x-ui.modal docblock, both of which deliberately
                         stayed dependency-free). --}}
                    <button type="button"
                            wire:click="resendCode"
                            data-resend-button
                            data-seconds="{{ $resendAvailableIn }}"
                            @disabled($resendAvailableIn > 0)
                            class="min-h-11 rounded text-sm font-medium text-slate-900 underline-offset-4 transition hover:underline disabled:cursor-not-allowed disabled:text-slate-400 disabled:no-underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
                        {{ $resendAvailableIn > 0 ? 'Resend in '.$resendAvailableIn.'s' : 'Resend code' }}
                    </button>
                </div>
            </form>
        @endif
    </x-ui.card>

    <p class="mt-6 text-center text-xs leading-relaxed text-slate-500">
        By continuing you agree to our
        <a href="{{ route('customer.terms') }}" class="font-medium text-slate-700 underline underline-offset-2 hover:text-slate-900">Terms of Use</a>
        and
        <a href="{{ route('customer.privacy') }}" class="font-medium text-slate-700 underline underline-offset-2 hover:text-slate-900">Privacy Policy</a>.
    </p>

    @script
    <script>
        // Ticks the resend button's own countdown down to zero and re-enables
        // it. Re-runs after every Livewire DOM update (a fresh request resets
        // data-seconds), and clears its interval when the element goes away.
        (function () {
            let timer = null;

            const start = () => {
                clearInterval(timer);
                const button = document.querySelector('[data-resend-button]');
                if (! button) return;

                let remaining = parseInt(button.dataset.seconds || '0', 10);
                if (remaining <= 0) return;

                timer = setInterval(() => {
                    remaining -= 1;
                    if (remaining > 0) {
                        button.textContent = `Resend in ${remaining}s`;
                        return;
                    }
                    clearInterval(timer);
                    button.disabled = false;
                    button.textContent = 'Resend code';
                }, 1000);
            };

            start();
            Livewire.hook('morphed', start);
        })();
    </script>
    @endscript
</div>
