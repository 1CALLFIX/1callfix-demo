@php $platformName = \App\Models\Setting::get('branding.platform_name', '1CallFix'); @endphp

<div class="mx-auto flex max-w-md flex-col justify-center px-4 py-12 sm:px-6 sm:py-20">

    <div class="text-center">
        <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">Sign in to {{ $platformName }}</h1>
        <p class="mt-2 text-sm text-slate-600">Use your mobile number or email and your password.</p>
    </div>

    <x-ui.card class="mt-8 !p-6 sm:!p-8">
        @if (session('status'))
            <div role="status" class="mb-5 rounded-lg bg-emerald-50 px-3 py-2.5 text-sm text-emerald-800">{{ session('status') }}</div>
        @endif

        @include('livewire.customer.auth._alerts')

        @if ($googleError)
            <div role="alert" class="mb-5 rounded-lg bg-red-50 px-3 py-2.5 text-sm text-red-800">{{ $googleError }}</div>
        @endif

        <form wire:submit="login" class="space-y-5">
            <div>
                <label for="identifier" class="block text-sm font-medium text-slate-900">Mobile number or email</label>
                <input id="identifier" type="text" autocomplete="username" autofocus wire:model="identifier"
                       @class(['mt-1.5 block min-h-11 w-full rounded-lg border px-3 py-2.5 text-base shadow-sm focus:outline focus:outline-2 focus:outline-offset-0 focus:outline-blue-600',
                               'border-red-400' => $errors->has('identifier'), 'border-slate-300' => ! $errors->has('identifier')])>
                @error('identifier') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>

            <div>
                <div class="flex items-center justify-between">
                    <label for="password" class="block text-sm font-medium text-slate-900">Password</label>
                    <a href="{{ route('customer.password.forgot') }}" wire:navigate class="text-sm font-medium text-slate-600 underline underline-offset-2 hover:text-slate-900">Forgot password?</a>
                </div>
                <input id="password" type="password" autocomplete="current-password" wire:model="password"
                       @class(['mt-1.5 block min-h-11 w-full rounded-lg border px-3 py-2.5 text-base shadow-sm focus:outline focus:outline-2 focus:outline-offset-0 focus:outline-blue-600',
                               'border-red-400' => $errors->has('password'), 'border-slate-300' => ! $errors->has('password')])>
                @error('password') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>

            <x-ui.button type="submit" size="lg" class="w-full !min-h-11" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="login">Sign in</span>
                <span wire:loading wire:target="login">Signing in…</span>
            </x-ui.button>
        </form>

        <div class="my-6 flex items-center gap-3 text-xs text-slate-400">
            <span class="h-px flex-1 bg-slate-200"></span> OR <span class="h-px flex-1 bg-slate-200"></span>
        </div>

        <div id="firebase-recaptcha"></div>
        <button type="button" data-google-signin
                class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-lg border border-slate-300 px-4 text-sm font-semibold text-slate-800 transition hover:bg-slate-50">
            Continue with Google
        </button>

        <p class="mt-6 text-center text-sm text-slate-600">
            New to {{ $platformName }}?
            <a href="{{ route('customer.signup') }}" wire:navigate class="font-medium text-slate-900 underline underline-offset-2">Create an account</a>
        </p>
    </x-ui.card>

    <p class="mt-6 text-center text-xs leading-relaxed text-slate-500">
        By continuing you agree to our
        <a href="{{ route('customer.terms') }}" class="font-medium text-slate-700 underline underline-offset-2">Terms of Use</a>
        and
        <a href="{{ route('customer.privacy') }}" class="font-medium text-slate-700 underline underline-offset-2">Privacy Policy</a>.
    </p>
</div>

@push('scripts')
    @vite('resources/js/customer-auth.js')
@endpush

@script
<script>
    (function () {
        const bind = () => {
            const btn = document.querySelector('[data-google-signin]');
            if (!btn || btn.dataset.bound) return;
            btn.dataset.bound = '1';
            btn.addEventListener('click', () => window.customerAuth && window.customerAuth.google());
        };
        bind();
        Livewire.hook('morphed', bind);
    })();
</script>
@endscript
