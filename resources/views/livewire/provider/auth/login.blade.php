@php $platformName = \App\Models\Setting::get('branding.platform_name', '1CallFix'); @endphp

<div class="mx-auto flex max-w-md flex-col justify-center px-4 py-12 sm:py-20">
    <div class="text-center">
        <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $platformName }} Partner</h1>
        <p class="mt-2 text-sm text-slate-600">Sign in with your registered mobile number or email and password.</p>
    </div>

    <x-ui.card class="mt-8 !p-6 sm:!p-8">
        @if ($error)
            <div role="alert" class="mb-5 rounded-lg bg-red-50 px-3 py-2.5 text-sm text-red-800">{{ $error }}</div>
        @endif

        <form wire:submit="login" class="space-y-5">
            <div>
                <label for="identifier" class="block text-sm font-medium text-slate-900">Mobile number or email</label>
                <input id="identifier" type="text" autocomplete="username" autofocus wire:model="identifier"
                       @class(['mt-1.5 block min-h-11 w-full rounded-lg border px-3 py-2.5 text-base shadow-sm focus:outline focus:outline-2 focus:outline-blue-600',
                               'border-red-400' => $errors->has('identifier'), 'border-slate-300' => ! $errors->has('identifier')])>
                @error('identifier') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-slate-900">Password</label>
                <x-ui.password-input id="password" name="password" autocomplete="current-password" wire:model="password" />
                @error('password') <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>

            <x-ui.button type="submit" size="lg" class="w-full !min-h-11" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="login">Sign in</span>
                <span wire:loading wire:target="login">Signing in…</span>
            </x-ui.button>
        </form>

        <p class="mt-6 text-center text-sm text-slate-600">
            New to {{ $platformName }}?
            <a href="{{ route('provider.register') }}" wire:navigate class="font-medium text-blue-600 underline underline-offset-2">Become a partner</a>
        </p>
        <p class="mt-2 text-center text-sm text-slate-600">
            Not a partner? <a href="{{ route('customer.login') }}" wire:navigate class="font-medium text-slate-900 underline underline-offset-2">Customer sign in</a>
        </p>
    </x-ui.card>
</div>
