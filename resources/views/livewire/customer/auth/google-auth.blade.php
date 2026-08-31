@php $platformName = \App\Models\Setting::get('branding.platform_name', '1CallFix'); @endphp

<div class="mx-auto flex max-w-md flex-col justify-center px-4 py-12 sm:px-6 sm:py-20">

    <div class="text-center">
        <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">One more step</h1>
        <p class="mt-2 text-sm text-slate-600">
            @if ($mode === 'link')
                Verify the mobile number on the account for {{ $googleEmail }} to link Google sign-in.
            @else
                A mobile number is required. Verify one to finish creating your account.
            @endif
        </p>
    </div>

    <x-ui.card class="mt-8 !p-6 sm:!p-8">
        @include('livewire.customer.auth._alerts')

        <div id="firebase-recaptcha"></div>

        <form wire:submit="requestPhoneCode" class="space-y-5">
            @if ($mode === 'new')
                <div>
                    <label for="ga-phone" class="block text-sm font-medium text-slate-900">Mobile number</label>
                    <input id="ga-phone" type="tel" inputmode="tel" autocomplete="tel" autofocus wire:model="phone"
                           placeholder="e.g. 9876543210"
                           @class(['mt-1.5 block min-h-11 w-full rounded-lg border px-3 py-2.5 text-base shadow-sm focus:outline focus:outline-2 focus:outline-slate-900',
                                   'border-red-400' => $errors->has('phone'), 'border-slate-300' => ! $errors->has('phone')])>
                    @error('phone') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
            @else
                <p class="text-sm text-slate-600">Code will be sent to <span class="font-medium text-slate-900">{{ $lockedPhoneE164 }}</span>.</p>
            @endif
            <x-ui.button type="submit" size="lg" class="w-full !min-h-11">Send verification code</x-ui.button>
        </form>

        <div class="mt-5">
            @include('livewire.customer.auth._firebase-phone', ['resend' => 'requestPhoneCode'])
        </div>

        <p class="mt-6 text-center text-sm text-slate-600">
            <a href="{{ route('customer.login') }}" wire:navigate class="font-medium text-slate-900 underline underline-offset-2">Cancel</a>
        </p>
    </x-ui.card>
</div>

@push('scripts')
    @vite('resources/js/customer-auth.js')
@endpush
