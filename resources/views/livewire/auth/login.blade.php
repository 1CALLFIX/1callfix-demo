<div class="min-h-screen flex items-center justify-center -mt-6">
    <x-ui.card class="!p-8 w-full max-w-sm">
        <h1 class="text-xl font-bold mb-6 text-center">{{ \App\Models\Setting::get('branding.platform_name', '1CallFix Admin') }}</h1>

        @if ($error)
            <div class="bg-red-50 text-red-700 text-sm rounded p-3 mb-4">
                {{ $error }}
            </div>
        @endif

        <form wire:submit="submit" class="space-y-4">
            <div>
                <label class="block text-sm font-medium mb-1">Email</label>
                <input type="email" wire:model="email"
                       class="w-full border rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-500">
                @error('email') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <div class="mb-1 flex items-center justify-between">
                    <label class="block text-sm font-medium">Password</label>
                    <a href="{{ route('customer.password.forgot', ['for' => 'admin']) }}" class="text-sm font-medium text-blue-600 hover:underline">Forgot password?</a>
                </div>
                <input type="password" wire:model="password"
                       class="w-full border rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-500">
                @error('password') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <x-ui.button type="submit" class="w-full" wire:loading.attr="disabled">
                <span wire:loading.remove>Log in</span>
                <span wire:loading>Logging in...</span>
            </x-ui.button>
        </form>
        <x-ui.app-version class="mt-6 text-center" />
    </x-ui.card>
</div>
