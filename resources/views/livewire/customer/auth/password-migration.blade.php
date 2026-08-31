@php $platformName = \App\Models\Setting::get('branding.platform_name', '1CallFix'); @endphp

<div class="mx-auto flex max-w-md flex-col justify-center px-4 py-12 sm:px-6 sm:py-20">

    <div class="text-center">
        <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">Set a password</h1>
        <p class="mt-2 text-sm text-slate-600">
            Your account was created before passwords. Verify your mobile number once to set one.
        </p>
    </div>

    <x-ui.card class="mt-8 !p-6 sm:!p-8">
        @include('livewire.customer.auth._alerts')

        <div id="firebase-recaptcha"></div>

        @if ($step === 'verify_phone')
            <p class="mb-4 text-sm text-slate-600">Code will be sent to <span class="font-medium text-slate-900">{{ $targetPhoneE164 }}</span>.</p>
            <button type="button" wire:click="sendPhoneCode"
                    class="mb-4 inline-flex min-h-11 w-full items-center justify-center rounded-lg bg-slate-900 px-4 text-sm font-semibold text-white hover:bg-slate-800">
                Send verification code
            </button>
            @include('livewire.customer.auth._firebase-phone', ['resend' => 'sendPhoneCode'])

        @else
            <form wire:submit="savePassword" class="space-y-5">
                <div>
                    <label for="pm-pw" class="block text-sm font-medium text-slate-900">Password</label>
                    <input id="pm-pw" type="password" autocomplete="new-password" autofocus wire:model="password"
                           @class(['mt-1.5 block min-h-11 w-full rounded-lg border px-3 py-2.5 text-base shadow-sm focus:outline focus:outline-2 focus:outline-slate-900',
                                   'border-red-400' => $errors->has('password'), 'border-slate-300' => ! $errors->has('password')])>
                    @error('password') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                    <p class="mt-1 text-xs text-slate-500">At least 8 characters.</p>
                </div>
                <div>
                    <label for="pm-pw2" class="block text-sm font-medium text-slate-900">Confirm password</label>
                    <input id="pm-pw2" type="password" autocomplete="new-password" wire:model="password_confirmation"
                           class="mt-1.5 block min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-base shadow-sm focus:outline focus:outline-2 focus:outline-slate-900">
                </div>
                <x-ui.button type="submit" size="lg" class="w-full !min-h-11">Save password and sign in</x-ui.button>
            </form>
        @endif
    </x-ui.card>
</div>

@push('scripts')
    @vite('resources/js/customer-auth.js')
@endpush
