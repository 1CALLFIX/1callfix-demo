<div class="min-h-screen flex items-center justify-center -mt-6">
    <div class="bg-white rounded-lg shadow-md p-8 w-full max-w-sm">
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
                <label class="block text-sm font-medium mb-1">Password</label>
                <input type="password" wire:model="password"
                       class="w-full border rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-500">
                @error('password') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <button type="submit"
                    class="w-full bg-slate-900 text-white rounded py-2 text-sm font-medium hover:bg-slate-800"
                    wire:loading.attr="disabled">
                <span wire:loading.remove>Log in</span>
                <span wire:loading>Logging in...</span>
            </button>
        </form>
    </div>
</div>
