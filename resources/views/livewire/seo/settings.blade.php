<div>
    <h1 class="text-xl font-semibold mb-1">Search &amp; social</h1>
    <p class="text-sm text-gray-500 mb-4">
        Every title, description and tag the public site shows to Google and on social shares. Blank fields fall back to the built-in wording,
        so a page is never left without a title. Every change is audit-logged. Canonical host: <code>{{ $canonicalBase }}</code>
        (change it under Settings → Site identity).
    </p>

    @if ($flashMessage)
        <div class="rounded px-4 py-2 mb-4 text-sm bg-green-50 text-green-700">{{ $flashMessage }}</div>
    @endif

    @php $input = 'w-full border rounded px-3 py-2 text-sm'; @endphp

    <form wire:submit="save" class="space-y-6">
        {{-- ------------------------------------------------------------ Site --}}
        <x-ui.card>
            <h2 class="text-sm font-semibold mb-3">Site-wide</h2>
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium mb-1" for="seo-template">Title template</label>
                    <input id="seo-template" type="text" wire:model.live.debounce.300ms="titleTemplate" placeholder="{page} · {site}" class="{{ $input }}">
                    <p class="text-xs text-gray-500 mt-1">Use <code>{page}</code> and <code>{site}</code>. Site name: <strong>{{ $siteName }}</strong> (Settings → Site identity).</p>
                    @error('titleTemplate') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" for="seo-image">Default share image URL</label>
                    <input id="seo-image" type="text" wire:model="defaultImageUrl" placeholder="/storage/seo/share.png or https://…" class="{{ $input }}">
                    <p class="text-xs text-gray-500 mt-1">1200×630 recommended. Blank = the site logo.</p>
                    @error('defaultImageUrl') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium mb-1" for="seo-desc">Default description</label>
                    <textarea id="seo-desc" rows="2" wire:model.live.debounce.300ms="defaultDescription" class="{{ $input }}"></textarea>
                    <p class="text-xs text-gray-500 mt-1">Used on any page that has no description of its own.</p>
                    @error('defaultDescription') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
            <x-seo.preview class="mt-4" :title="\App\Services\Seo\SeoSettings::renderTitle('How it works', $template)" :description="$defaultDescription ?: \App\Services\Seo\SeoSettings::defaultDescription()" :url="$canonicalBase.'/how-it-works'" />
        </x-ui.card>

        {{-- ------------------------------------------------------------ Home --}}
        <x-ui.card>
            <h2 class="text-sm font-semibold mb-3">Home page</h2>
            <div class="grid gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1" for="seo-home-title">Home title (complete — the template is not added)</label>
                    <input id="seo-home-title" type="text" wire:model.live.debounce.300ms="homeTitle" placeholder="Home services, on call" class="{{ $input }}">
                    @error('homeTitle') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" for="seo-home-desc">Home description</label>
                    <textarea id="seo-home-desc" rows="2" wire:model.live.debounce.300ms="homeDescription" class="{{ $input }}"></textarea>
                    @error('homeDescription') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
            <x-seo.preview class="mt-4" :title="$homePreviewIsComplete ? $homePreviewTitle : \App\Services\Seo\SeoSettings::renderTitle($homePreviewTitle, $template)" :description="$homeDescription ?: ($defaultDescription ?: \App\Services\Seo\SeoSettings::defaultDescription())" :url="$canonicalBase.'/'" />
        </x-ui.card>

        {{-- ------------------------------------------------------------ Modules --}}
        <x-ui.card>
            <h2 class="text-sm font-semibold mb-1">Per module</h2>
            <p class="text-xs text-gray-500 mb-3">Every registered module is listed, so a module you switch on later already has a place here. Today only the Services module has public pages (the <code>/services</code> list uses its title and description).</p>
            <div class="space-y-4">
                @foreach ($modules as $module)
                    <div class="border rounded p-3" wire:key="seo-module-{{ $module->code }}">
                        <p class="text-sm font-medium">{{ $module->name }}
                            <span class="ml-2 text-xs {{ $module->is_active ? 'text-green-700' : 'text-gray-400' }}">{{ $module->is_active ? 'active' : 'inactive' }}</span>
                        </p>
                        <div class="grid gap-3 md:grid-cols-2 mt-2">
                            <input type="text" wire:model.live.debounce.300ms="moduleTitles.{{ $module->code }}" placeholder="Title" class="{{ $input }}">
                            <input type="text" wire:model.live.debounce.300ms="moduleDescriptions.{{ $module->code }}" placeholder="Description" class="{{ $input }}">
                        </div>
                        @error('moduleTitles.'.$module->code) <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                        @error('moduleDescriptions.'.$module->code) <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </div>
        </x-ui.card>

        {{-- ------------------------------------------------------------ Business --}}
        <x-ui.card>
            <h2 class="text-sm font-semibold mb-1">Business details (structured data)</h2>
            <p class="text-xs text-gray-500 mb-3">Used for the Organization and LocalBusiness blocks on the home page. LocalBusiness is only published once name, phone, street, city and postal code are all filled — never from guesses.</p>
            <div class="grid gap-3 md:grid-cols-3">
                @foreach (['legal_name' => 'Business name', 'phone' => 'Phone (+91…)', 'email' => 'Email', 'street' => 'Street address', 'city' => 'City', 'region' => 'State / region', 'postal_code' => 'Postal code', 'country' => 'Country (2 letters, default IN)'] as $field => $label)
                    <div>
                        <label class="block text-xs font-medium mb-1" for="seo-b-{{ $field }}">{{ $label }}</label>
                        <input id="seo-b-{{ $field }}" type="text" wire:model="business.{{ $field }}" class="{{ $input }}">
                        @error('business.'.$field) <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </div>
        </x-ui.card>

        {{-- ------------------------------------------------------------ Verification + host --}}
        <x-ui.card>
            <h2 class="text-sm font-semibold mb-3">Search-console verification</h2>
            <div class="grid gap-3 md:grid-cols-2">
                <div>
                    <label class="block text-xs font-medium mb-1" for="seo-google">Google (content value of the meta tag)</label>
                    <input id="seo-google" type="text" wire:model="verifyGoogle" class="{{ $input }}">
                    @error('verifyGoogle') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" for="seo-bing">Bing (content value of msvalidate.01)</label>
                    <input id="seo-bing" type="text" wire:model="verifyBing" class="{{ $input }}">
                    @error('verifyBing') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            <h2 class="text-sm font-semibold mt-6 mb-2">Host redirect</h2>
            <label class="flex items-start gap-2 text-sm">
                <input type="checkbox" wire:model="redirectHost" class="mt-1 rounded border-gray-300">
                <span>Redirect www., api. and http:// visits of public pages to <code>{{ $canonicalBase }}</code> (301, query kept).
                    <span class="block text-xs text-gray-500">Off by default. Never touches /api/*, webhooks, health checks, uploads or form posts, so the mobile API keeps working. Turning this on is your host-cutover decision.</span>
                </span>
            </label>
        </x-ui.card>

        <x-ui.button type="submit">Save SEO settings</x-ui.button>
    </form>
</div>
