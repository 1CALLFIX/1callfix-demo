{{-- Join in 2 minutes (owner 2026-11-12): sign in with a code sent to the mobile — the same Firebase phone check as
     sign-up (SignsInWithMobileCode). Used by the partner / hotel, seller / restaurant and rider sign-ins. --}}
<div id="firebase-recaptcha"></div>
@if ($status) <div role="status" class="mb-4 rounded-lg bg-blue-50 px-3 py-2.5 text-sm text-blue-900">{{ $status }}</div> @endif
<form wire:submit="requestCode" class="space-y-5" data-testid="login-code">
    <div>
        <label for="pl-phone" class="block text-sm font-medium text-slate-900">Mobile number</label>
        <input id="pl-phone" type="tel" inputmode="tel" autocomplete="tel" autofocus wire:model="phone" placeholder="e.g. 9876543210" @if ($codeStep === 'verify') readonly @endif
               @class(['mt-1.5 block min-h-11 w-full rounded-lg border px-3 py-2.5 text-base shadow-sm focus:outline focus:outline-2 focus:outline-blue-600',
                       'border-red-400' => $errors->has('phone'), 'border-slate-300' => ! $errors->has('phone')])>
        @error('phone') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
    </div>
    @if ($codeStep === 'verify')
        @include('livewire.customer.auth._firebase-phone', ['resend' => 'requestCode'])
    @else
        <x-ui.button type="submit" size="lg" class="w-full !min-h-11" wire:loading.attr="disabled" wire:target="requestCode">
            <span wire:loading.remove wire:target="requestCode">Send code</span>
            <span wire:loading wire:target="requestCode">Sending…</span>
        </x-ui.button>
    @endif
</form>
<button type="button" wire:click="$set('mode', 'password')" class="mt-4 flex min-h-11 w-full items-center justify-center text-sm font-medium text-slate-600 underline underline-offset-4 hover:text-slate-900">Sign in with a password instead</button>
