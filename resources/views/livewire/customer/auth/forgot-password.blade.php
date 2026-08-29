@php $platformName = \App\Models\Setting::get('branding.platform_name', '1CallFix'); @endphp

<div class="mx-auto flex max-w-md flex-col justify-center px-4 py-12 sm:px-6 sm:py-20">

    <div class="text-center">
        <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">Reset your password</h1>
        <p class="mt-2 text-sm text-slate-600">
            @if ($step === 'identifier') Enter the mobile number or email on your account.
            @elseif ($step === 'new_password') Choose a new password.
            @else Confirm the code we sent you.
            @endif
        </p>
    </div>

    <x-ui.card class="mt-8 !p-6 sm:!p-8">
        @include('livewire.customer.auth._alerts')

        <div id="firebase-recaptcha"></div>

        @if ($step === 'identifier')
            <form wire:submit="submitIdentifier" class="space-y-5">
                <div>
                    <label for="fp-id" class="block text-sm font-medium text-slate-900">Mobile number or email</label>
                    <input id="fp-id" type="text" autocomplete="username" autofocus wire:model="identifier"
                           @class(['mt-1.5 block min-h-11 w-full rounded-lg border px-3 py-2.5 text-base shadow-sm focus:outline focus:outline-2 focus:outline-slate-900',
                                   'border-red-400' => $errors->has('identifier'), 'border-slate-300' => ! $errors->has('identifier')])>
                    @error('identifier') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                <x-ui.button type="submit" size="lg" class="w-full !min-h-11">Continue</x-ui.button>
            </form>

        @elseif ($step === 'email_code')
            <form wire:submit="verifyEmailCode" class="space-y-5">
                <div>
                    <label for="fp-code" class="block text-sm font-medium text-slate-900">Verification code</label>
                    <input id="fp-code" type="text" inputmode="numeric" maxlength="8" autofocus wire:model="code"
                           class="mt-1.5 block min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-center text-2xl font-semibold tracking-[0.4em] shadow-sm focus:outline focus:outline-2 focus:outline-slate-900">
                    @error('code') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                <x-ui.button type="submit" size="lg" class="w-full !min-h-11">Verify</x-ui.button>
            </form>

        @elseif ($step === 'verify_phone')
            @include('livewire.customer.auth._firebase-phone', ['resend' => 'submitIdentifier'])

        @else
            <form wire:submit="setNewPassword" class="space-y-5">
                <div>
                    <label for="fp-pw" class="block text-sm font-medium text-slate-900">New password</label>
                    <input id="fp-pw" type="password" autocomplete="new-password" autofocus wire:model="password"
                           @class(['mt-1.5 block min-h-11 w-full rounded-lg border px-3 py-2.5 text-base shadow-sm focus:outline focus:outline-2 focus:outline-slate-900',
                                   'border-red-400' => $errors->has('password'), 'border-slate-300' => ! $errors->has('password')])>
                    @error('password') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                    <p class="mt-1 text-xs text-slate-500">At least 8 characters.</p>
                </div>
                <div>
                    <label for="fp-pw2" class="block text-sm font-medium text-slate-900">Confirm new password</label>
                    <input id="fp-pw2" type="password" autocomplete="new-password" wire:model="password_confirmation"
                           class="mt-1.5 block min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-base shadow-sm focus:outline focus:outline-2 focus:outline-slate-900">
                </div>
                <x-ui.button type="submit" size="lg" class="w-full !min-h-11">Update password</x-ui.button>
            </form>
        @endif

        <p class="mt-6 text-center text-sm text-slate-600">
            <a href="{{ route('customer.login') }}" wire:navigate class="font-medium text-slate-900 underline underline-offset-2">Back to sign in</a>
        </p>
    </x-ui.card>
</div>

@push('scripts')
    @vite('resources/js/customer-auth.js')
@endpush
