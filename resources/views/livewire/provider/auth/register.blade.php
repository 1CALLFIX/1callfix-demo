@php $platformName = \App\Models\Setting::get('branding.platform_name', '1CallFix'); @endphp

<div class="mx-auto flex max-w-lg flex-col justify-center px-4 py-12 sm:py-16">
    <div class="text-center">
        <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">Become a {{ $platformName }} partner</h1>
        <p class="mt-2 text-sm text-slate-600">
            @if ($submitted)
                Thanks — your application is in.
            @else
                Verify your mobile number and fill in your details and documents together — the code
                arrives while you type the rest.
            @endif
        </p>
    </div>

    <x-ui.card class="mt-8 !p-6 sm:!p-8">
        @if ($submitted)
            <div role="status" class="space-y-4 text-sm text-slate-700">
                <div class="flex items-start gap-2 rounded-lg bg-emerald-50 px-3 py-2.5 text-emerald-800">
                    <span aria-hidden="true" class="mt-px font-bold">&check;</span>
                    <span>Application received.</span>
                </div>
                <p>
                    Our team will review your details and documents and contact you on
                    <span class="font-medium">{{ $verifiedPhoneE164 }}</span>. You can’t sign in until
                    your application is approved.
                </p>
                @if ($outOfCoverage)
                    <p class="rounded-lg bg-amber-50 px-3 py-2.5 text-amber-800">
                        We don’t have full coverage in your area yet — an operator will place your
                        application with the nearest team.
                    </p>
                @endif
                <a href="{{ route('provider.login') }}" wire:navigate
                   class="inline-block font-medium text-blue-600 underline underline-offset-2">Back to partner sign in</a>
            </div>
        @else
            @include('livewire.customer.auth._alerts')

            {{-- Persistent invisible-reCAPTCHA host — rendered once, step-independently,
                 so a Livewire morph never detaches the Firebase widget mid-flow. --}}
            <div id="firebase-recaptcha"></div>

            {{-- ─────────────── Mobile number + OTP ───────────────
                 Its own <form> so Enter here requests a code (never submits the
                 application), and so the "Send code" / "Verify" buttons don't
                 post the details form below. The applicant fills everything
                 under here while the SMS is on its way. --}}
            <form wire:submit="requestPhoneCode" class="rounded-lg border border-slate-200 p-3 sm:p-4">
                <label for="pr-phone" class="block text-sm font-medium text-slate-900">Mobile number</label>

                @if ($step === 'details' && $verifiedPhoneE164)
                    <div class="mt-1.5 flex items-center justify-between gap-3 rounded-lg bg-emerald-50 px-3 py-2.5 text-sm text-emerald-800">
                        <span><span aria-hidden="true" class="font-bold">&check;</span> Verified — {{ $verifiedPhoneE164 }}</span>
                        <button type="button" wire:click="changePhone"
                                class="shrink-0 font-medium underline underline-offset-2 hover:text-emerald-900">Change</button>
                    </div>
                @else
                    <input id="pr-phone" type="tel" inputmode="tel" autocomplete="tel" autofocus wire:model="phone"
                           placeholder="e.g. 9876543210"
                           @class(['mt-1.5 block min-h-11 w-full rounded-lg border px-3 py-2.5 text-base shadow-sm focus:outline focus:outline-2 focus:outline-blue-600',
                                   'border-red-400' => $errors->has('phone'), 'border-slate-300' => ! $errors->has('phone')])>
                    @error('phone') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                    <p class="mt-1 text-xs text-slate-500">This becomes your partner sign-in number.</p>

                    @if ($step === 'verify_phone')
                        <div class="mt-4">
                            @include('livewire.customer.auth._firebase-phone', ['resend' => 'requestPhoneCode'])
                        </div>
                        <button type="button" wire:click="changePhone"
                                class="mt-3 text-sm font-medium text-slate-600 underline underline-offset-4 hover:text-slate-900">
                            Change number
                        </button>
                    @else
                        <x-ui.button type="submit" size="lg" class="mt-3 w-full !min-h-11" wire:loading.attr="disabled" wire:target="requestPhoneCode">
                            <span wire:loading.remove wire:target="requestPhoneCode">Send verification code</span>
                            <span wire:loading wire:target="requestPhoneCode">Sending…</span>
                        </x-ui.button>
                    @endif
                @endif
            </form>

            {{-- ─────────────── Your details, work area & KYC ───────────────
                 Always visible and fillable — no step gate. submitApplication()
                 refuses only until verifiedPhoneE164 is set, so an applicant can
                 fill all of this before, during or after the OTP round-trip. --}}
            <form wire:submit="submitApplication" class="mt-5 space-y-5">
                <div>
                    <label for="pr-name" class="block text-sm font-medium text-slate-900">Full name</label>
                    <input id="pr-name" type="text" autocomplete="name" wire:model="name"
                           @class(['mt-1.5 block min-h-11 w-full rounded-lg border px-3 py-2.5 text-base shadow-sm focus:outline focus:outline-2 focus:outline-blue-600',
                                   'border-red-400' => $errors->has('name'), 'border-slate-300' => ! $errors->has('name')])>
                    @error('name') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="pr-password" class="block text-sm font-medium text-slate-900">Password</label>
                    <x-ui.password-input id="pr-password" name="password" autocomplete="new-password" wire:model="password" />
                    @error('password') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                    <p class="mt-1 text-xs text-slate-500">At least 8 characters.</p>
                </div>

                <div>
                    <label for="pr-password2" class="block text-sm font-medium text-slate-900">Confirm password</label>
                    <x-ui.password-input id="pr-password2" name="password_confirmation" autocomplete="new-password" wire:model="password_confirmation" />
                </div>

                <div>
                    <label for="pr-email" class="block text-sm font-medium text-slate-900">Email <span class="font-normal text-slate-500">(optional)</span></label>
                    <input id="pr-email" type="email" autocomplete="email" wire:model="email" placeholder="you@example.com"
                           @class(['mt-1.5 block min-h-11 w-full rounded-lg border px-3 py-2.5 text-base shadow-sm focus:outline focus:outline-2 focus:outline-blue-600',
                                   'border-red-400' => $errors->has('email'), 'border-slate-300' => ! $errors->has('email')])>
                    @error('email') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="pr-address" class="block text-sm font-medium text-slate-900">Work address</label>
                    <textarea id="pr-address" rows="2" wire:model="address" placeholder="Area / street you work from"
                              @class(['mt-1.5 block w-full rounded-lg border px-3 py-2.5 text-base shadow-sm focus:outline focus:outline-2 focus:outline-blue-600',
                                      'border-red-400' => $errors->has('address'), 'border-slate-300' => ! $errors->has('address')])></textarea>
                    @error('address') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror

                    <button type="button" data-locate-address
                            class="mt-2 inline-flex min-h-11 items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-800 hover:bg-slate-50">
                        <span data-locate-address-label>Use my current location</span>
                    </button>

                    {{-- Revealed by cfWireLocateButton when the browser
                         geolocation lookup fails or is declined — same
                         visible-error + manual-fallback pattern as the
                         customer location picker. The typed address above
                         is enough on its own; submitApplication() accepts
                         it and flags the row for operator placement. --}}
                    <p data-locate-address-error role="alert" hidden
                       class="mt-1.5 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        We could not get your location. Type your work address above instead.
                    </p>

                    @if ($resolvedZoneName && ! $outOfCoverage)
                        <p class="mt-1.5 text-xs text-emerald-700">
                            Pinned — service area <span class="font-medium">{{ $resolvedZoneName }}</span>.
                        </p>
                    @elseif ($outOfCoverage)
                        <p class="mt-1.5 text-xs text-amber-700">
                            We don’t operate there yet, but you can still apply — an operator will place you
                            with the nearest team{{ $resolvedZoneName ? ' ('.$resolvedZoneName.')' : '' }}.
                        </p>
                    @endif
                </div>

                <fieldset class="space-y-3 rounded-lg border border-slate-200 p-3">
                    <legend class="px-1 text-xs font-medium text-slate-500">KYC documents (JPG, PNG or PDF, up to 10&nbsp;MB each)</legend>
                    @foreach ($requirements as $req)
                        <div>
                            <label class="block text-sm font-medium text-slate-900">
                                {{ $req->label }}
                                @if ($req->is_required)
                                    <span class="text-red-600">*</span>
                                @else
                                    <span class="font-normal text-slate-500">(if applicable)</span>
                                @endif
                            </label>
                            <input type="file" accept=".jpg,.jpeg,.png,.pdf" wire:model="documents.{{ $req->document_type }}"
                                   class="mt-1.5 block w-full text-sm file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-medium">
                            <div wire:loading wire:target="documents.{{ $req->document_type }}" class="mt-1 text-xs text-slate-500">Uploading…</div>
                            @error('documents.'.$req->document_type) <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                </fieldset>

                <label class="flex items-start gap-2 text-sm text-slate-700">
                    <input type="checkbox" wire:model="terms" class="mt-0.5 h-4 w-4 accent-blue-600">
                    <span>I agree to the {{ $platformName }} partner terms and confirm my details are accurate.</span>
                </label>
                @error('terms') <p class="-mt-2 text-sm text-red-700">{{ $message }}</p> @enderror

                @unless ($step === 'details' && $verifiedPhoneE164)
                    <p class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">
                        Fill everything in now — just verify your mobile number above before you submit.
                    </p>
                @endunless

                <x-ui.button type="submit" size="lg" class="w-full !min-h-11" wire:loading.attr="disabled" wire:target="submitApplication">
                    <span wire:loading.remove wire:target="submitApplication">Submit application</span>
                    <span wire:loading wire:target="submitApplication">Submitting…</span>
                </x-ui.button>
            </form>
        @endif
    </x-ui.card>

    @unless ($submitted)
        <p class="mt-6 text-center text-sm text-slate-600">
            Already a partner?
            <a href="{{ route('provider.login') }}" wire:navigate class="font-medium text-slate-900 underline underline-offset-2">Sign in</a>
        </p>
    @endunless
</div>

@push('scripts')
    @vite('resources/js/customer-auth.js')
@endpush

@script
<script>window.cfWireLocateButton($wire);</script>
@endscript
