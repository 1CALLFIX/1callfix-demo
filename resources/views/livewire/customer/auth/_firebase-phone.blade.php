{{-- Firebase phone-OTP sub-widget, shared by every screen that verifies a
     mobile number. The invisible reCAPTCHA container is required by the
     Firebase JS SDK. The code field calls window.customerAuth.confirmPhone,
     which posts the resulting ID token back as the Livewire event
     `firebase-phone-token` — always re-verified server-side.

     Plain JS (no Alpine), matching this codebase's own auth-screen
     convention. $resend = the component action to re-request a code. --}}
<div class="space-y-4">
    {{-- The #firebase-recaptcha container is rendered once, step-independently,
         by the parent view so the invisible widget is never detached by a
         Livewire morph mid-flow. --}}
    <div>
        <label for="fb-otp" class="block text-sm font-medium text-slate-900">Verification code</label>
        <input id="fb-otp" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="8"
               class="mt-1.5 block min-h-11 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-center text-2xl font-semibold tracking-[0.4em] shadow-sm focus:outline focus:outline-2 focus:outline-slate-900">
    </div>

    <button type="button" data-confirm-phone
            class="inline-flex min-h-11 w-full items-center justify-center rounded-lg bg-slate-900 px-4 text-sm font-semibold text-white transition hover:bg-slate-800">
        Verify code
    </button>

    <p class="text-xs text-slate-500">
        Didn’t get it? Wait a moment, then
        <button type="button" wire:click="{{ $resend ?? 'requestPhoneCode' }}"
                class="font-medium text-slate-700 underline underline-offset-2 hover:text-slate-900">resend</button>.
    </p>
</div>

@script
<script>
    (function () {
        const bind = () => {
            const otp = document.getElementById('fb-otp');
            const btn = document.querySelector('[data-confirm-phone]');
            if (!btn || btn.dataset.bound) return;
            btn.dataset.bound = '1';
            const submit = () => window.customerAuth && window.customerAuth.confirmPhone(otp ? otp.value : '');
            btn.addEventListener('click', submit);
            otp && otp.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); submit(); } });
        };
        bind();
        Livewire.hook('morphed', bind);
    })();
</script>
@endscript
