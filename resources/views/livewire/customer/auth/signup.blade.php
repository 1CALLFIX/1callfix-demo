@php $platformName = \App\Models\Setting::get('branding.platform_name', '1CallFix'); @endphp

<div class="mx-auto flex max-w-md flex-col justify-center px-4 py-12 sm:px-6 sm:py-16">

    <div class="text-center">
        <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">Create your {{ $platformName }} account</h1>
        <p class="mt-2 text-sm text-slate-600">
            @if ($step === 'phone') We’ll verify your mobile number, then you choose a password.
            @elseif ($step === 'verify_phone') Enter the 6-digit code we texted you.
            @else Almost done — set a name and password.
            @endif
        </p>
    </div>

    <x-ui.card class="mt-8 !p-6 sm:!p-8">
        @include('livewire.customer.auth._alerts')

        {{-- Persistent invisible-reCAPTCHA host — never removed by a step morph. --}}
        <div id="firebase-recaptcha"></div>

        @if ($step === 'phone')
            <form wire:submit="requestPhoneCode" class="space-y-5">
                <div>
                    <label for="su-phone" class="block text-sm font-medium text-slate-900">Mobile number</label>
                    <input id="su-phone" type="tel" inputmode="tel" autocomplete="tel" autofocus wire:model="phone"
                           placeholder="e.g. 9876543210"
                           @class(['mt-1.5 block min-h-11 w-full rounded-lg border px-3 py-2.5 text-base shadow-sm focus:outline focus:outline-2 focus:outline-slate-900',
                                   'border-red-400' => $errors->has('phone'), 'border-slate-300' => ! $errors->has('phone')])>
                    @error('phone') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                <x-ui.button type="submit" size="lg" class="w-full !min-h-11" wire:loading.attr="disabled">Send verification code</x-ui.button>
            </form>

        @elseif ($step === 'verify_phone')
            @include('livewire.customer.auth._firebase-phone', ['resend' => 'requestPhoneCode'])
            <button type="button" wire:click="changePhone" class="mt-4 text-sm font-medium text-slate-600 underline underline-offset-4 hover:text-slate-900">Change number</button>

        @else
            <form wire:submit="completeSignup" class="space-y-5">
                <div>
                    <label for="su-name" class="block text-sm font-medium text-slate-900">Your name</label>
                    <input id="su-name" type="text" autocomplete="name" autofocus wire:model="name"
                           @class(['mt-1.5 block min-h-11 w-full rounded-lg border px-3 py-2.5 text-base shadow-sm focus:outline focus:outline-2 focus:outline-slate-900',
                                   'border-red-400' => $errors->has('name'), 'border-slate-300' => ! $errors->has('name')])>
                    @error('name') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="su-password" class="block text-sm font-medium text-slate-900">Password</label>
                    <input id="su-password" type="password" autocomplete="new-password" wire:model="password"
                           @class(['mt-1.5 block min-h-11 w-full rounded-lg border px-3 py-2.5 text-base shadow-sm focus:outline focus:outline-2 focus:outline-slate-900',
                                   'border-red-400' => $errors->has('password'), 'border-slate-300' => ! $errors->has('password')])>
                    @error('password') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                    <p class="mt-1 text-xs text-slate-500">At least 8 characters.</p>
                </div>

                <div>
                    <label for="su-password2" class="block text-sm font-medium text-slate-900">Confirm password</label>
                    <input id="su-password2" type="password" autocomplete="new-password" wire:model="password_confirmation"
                           class="mt-1.5 block min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-base shadow-sm focus:outline focus:outline-2 focus:outline-slate-900">
                </div>

                <fieldset class="rounded-lg border border-slate-200 p-3">
                    <legend class="px-1 text-xs font-medium text-slate-500">Email (optional)</legend>
                    <div class="flex gap-2">
                        <input id="su-email" type="email" autocomplete="email" wire:model="email" placeholder="you@example.com"
                               @disabled($verifiedEmail !== '')
                               class="block min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm shadow-sm focus:outline focus:outline-2 focus:outline-slate-900 disabled:bg-slate-50">
                        @if ($verifiedEmail === '')
                            <button type="button" wire:click="sendEmailCode" class="shrink-0 rounded-lg border border-slate-300 px-3 text-sm font-medium hover:bg-slate-50">Send code</button>
                        @else
                            <span class="inline-flex items-center rounded-lg bg-emerald-50 px-3 text-sm font-medium text-emerald-700">Verified</span>
                        @endif
                    </div>
                    @error('email') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror

                    @if ($emailCodeSent && $verifiedEmail === '')
                        <div class="mt-3 flex gap-2">
                            <input type="text" inputmode="numeric" maxlength="8" wire:model="emailCode" placeholder="Email code"
                                   class="block min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm shadow-sm">
                            <button type="button" wire:click="verifyEmailCode" class="shrink-0 rounded-lg bg-slate-900 px-3 text-sm font-semibold text-white">Verify</button>
                        </div>
                    @endif
                </fieldset>

                <x-ui.button type="submit" size="lg" class="w-full !min-h-11" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="completeSignup">Create account</span>
                    <span wire:loading wire:target="completeSignup">Creating…</span>
                </x-ui.button>
            </form>
        @endif
    </x-ui.card>

    <p class="mt-6 text-center text-sm text-slate-600">
        Already have an account?
        <a href="{{ route('customer.login') }}" wire:navigate class="font-medium text-slate-900 underline underline-offset-2">Sign in</a>
    </p>
</div>

@push('scripts')
    @vite('resources/js/customer-auth.js')
@endpush
