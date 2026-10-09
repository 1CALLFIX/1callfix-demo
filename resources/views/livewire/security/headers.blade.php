<div>
    <h1 class="text-xl font-semibold mb-1">Security headers</h1>
    <p class="text-sm text-gray-500 mb-4">
        The HTTP headers the site sends so browsers block clickjacking, content sniffing and insecure loading. Every value is yours to change; blank
        text fields fall back to the safe built-in. Every change is audit-logged. Changes apply to new page loads straight away.
    </p>

    @if ($flashMessage)
        <div class="rounded px-4 py-2 mb-4 text-sm bg-green-50 text-green-700">{{ $flashMessage }}</div>
    @endif

    @php $input = 'w-full border rounded px-3 py-2 text-sm'; @endphp

    <form wire:submit="save" class="space-y-6">
        <x-ui.card>
            <label class="flex items-start gap-2 text-sm">
                <input type="checkbox" wire:model="enabled" class="mt-1 rounded border-gray-300">
                <span><strong>Send security headers</strong>
                    <span class="block text-xs text-gray-500">Master switch. Off = none of the headers below are sent.</span></span>
            </label>
        </x-ui.card>

        <x-ui.card>
            <h2 class="text-sm font-semibold mb-3">Safe by default</h2>
            <div class="space-y-4">
                <label class="flex items-start gap-2 text-sm">
                    <input type="checkbox" wire:model="nosniff" class="mt-1 rounded border-gray-300">
                    <span>Stop browsers guessing file types <code>(X-Content-Type-Options: nosniff)</code></span>
                </label>

                <div>
                    <label class="flex items-start gap-2 text-sm">
                        <input type="checkbox" wire:model="referrerOn" class="mt-1 rounded border-gray-300">
                        <span>Limit what other sites learn from links you click <code>(Referrer-Policy)</code></span>
                    </label>
                    <select wire:model="referrer" class="{{ $input }} mt-2 md:w-1/2" aria-label="Referrer policy">
                        @foreach ($referrerOptions as $option) <option value="{{ $option }}">{{ $option }}</option> @endforeach
                    </select>
                    @error('referrer') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="flex items-start gap-2 text-sm">
                        <input type="checkbox" wire:model="frameOn" class="mt-1 rounded border-gray-300">
                        <span>Stop other sites showing yours inside a frame <code>(X-Frame-Options)</code></span>
                    </label>
                    <select wire:model="frame" class="{{ $input }} mt-2 md:w-1/2" aria-label="Frame options">
                        @foreach ($frameOptions as $option) <option value="{{ $option }}">{{ $option }}</option> @endforeach
                    </select>
                    <p class="text-xs text-gray-500 mt-1">SAMEORIGIN lets your own pages frame each other; DENY blocks all framing.</p>
                    @error('frame') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
        </x-ui.card>

        <x-ui.card>
            <h2 class="text-sm font-semibold mb-1">HTTPS only <span class="text-xs font-normal text-gray-500">(HSTS)</span></h2>
            <p class="text-xs text-amber-700 mb-3">Off by default. Browsers remember this for the whole max-age, so turn it on only when every part of the site works over https. Sent on https requests only.</p>
            <label class="flex items-start gap-2 text-sm mb-3">
                <input type="checkbox" wire:model="hsts" class="mt-1 rounded border-gray-300">
                <span>Tell browsers to always use https for this site</span>
            </label>
            <div class="grid gap-4 md:grid-cols-3">
                <div>
                    <label class="block text-xs font-medium mb-1" for="hsts-age">max-age (seconds)</label>
                    <input id="hsts-age" type="number" wire:model="hstsMaxAge" class="{{ $input }}">
                    <p class="text-xs text-gray-500 mt-1">31536000 = 1 year. Start small (e.g. 300) to test.</p>
                    @error('hstsMaxAge') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <label class="flex items-start gap-2 text-sm md:mt-5">
                    <input type="checkbox" wire:model="hstsSubdomains" class="mt-1 rounded border-gray-300">
                    <span>Include subdomains <span class="block text-xs text-gray-500">Covers api., www. and every other subdomain.</span></span>
                </label>
                <label class="flex items-start gap-2 text-sm md:mt-5">
                    <input type="checkbox" wire:model="hstsPreload" class="mt-1 rounded border-gray-300">
                    <span>Preload <span class="block text-xs text-gray-500">Needs subdomains on; very hard to undo — only if you plan to submit to the preload list.</span></span>
                </label>
            </div>
        </x-ui.card>

        <x-ui.card>
            <h2 class="text-sm font-semibold mb-3">Content Security Policy</h2>
            <p class="text-xs text-gray-500 mb-3">Lists exactly which sites the pages may load scripts, images and frames from. A wrong policy can break payments or login, so
                use <strong>Report only</strong> first: nothing is blocked, and the browser console shows what would have been. Switch to <strong>Enforce</strong> when it is quiet.</p>
            <div class="mb-3">
                <label class="block text-xs font-medium mb-1" for="csp-mode">Mode</label>
                <select id="csp-mode" wire:model="cspMode" class="{{ $input }} md:w-1/3">
                    <option value="off">Off</option>
                    <option value="report_only">Report only (test)</option>
                    <option value="enforce">Enforce</option>
                </select>
                @error('cspMode') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <label class="block text-xs font-medium mb-1" for="csp-policy">Policy (one line)</label>
            <textarea id="csp-policy" rows="6" wire:model="csp" placeholder="{{ $defaultCsp }}" class="{{ $input }} font-mono text-xs"></textarea>
            <div class="flex items-center gap-3 mt-1">
                <button type="button" wire:click="useDefaultCsp" class="text-xs underline text-blue-700">Fill in the starter policy</button>
                <span class="text-xs text-gray-500">Blank = the starter policy (allows Razorpay, Firebase and Google sign-in).</span>
            </div>
            @error('csp') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </x-ui.card>

        <x-ui.card>
            <h2 class="text-sm font-semibold mb-3">Permissions-Policy</h2>
            <label class="flex items-start gap-2 text-sm mb-3">
                <input type="checkbox" wire:model="permissionsOn" class="mt-1 rounded border-gray-300">
                <span>Control which browser features (location, camera, microphone, payment) pages may use. Off by default.</span>
            </label>
            <textarea rows="2" wire:model="permissions" placeholder="{{ $defaultPermissions }}" class="{{ $input }} font-mono text-xs" aria-label="Permissions-Policy value"></textarea>
            <button type="button" wire:click="useDefaultPermissions" class="text-xs underline text-blue-700 mt-1">Fill in the suggested value</button>
            @error('permissions') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </x-ui.card>

        <x-ui.button type="submit">Save security headers</x-ui.button>
    </form>
</div>
