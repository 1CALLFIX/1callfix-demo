{{-- Application form (REF 1CF-PARTNER-PAGE-001). Wording comes from the settings store via the component. --}}
<div x-data x-on:partner-role-select.window="$wire.set('role', $event.detail.role)" class="mt-8">
    @if ($outcome === 'handoff')
        <div role="status" class="rounded-2xl bg-white p-6 text-slate-900">
            <h3 class="text-xl font-bold">Thank you, we have your details</h3>
            <p class="mt-2 text-sm text-slate-600">Next, verify your mobile number and upload your documents in the app sign-up.</p>
            <a href="{{ route('provider.register') }}" class="mt-5 inline-flex min-h-11 items-center rounded-lg bg-amber-400 px-6 py-3 text-sm font-semibold text-slate-900 hover:bg-amber-300">Continue in the app</a>
        </div>
    @elseif ($outcome !== '')
        <div role="status" class="rounded-2xl bg-white p-6 text-slate-900">
            @if (filled($doneTitle)) <h3 class="text-xl font-bold">{{ $doneTitle }}</h3> @endif
            @if (filled($doneBody)) <p class="mt-2 text-sm text-slate-600">{{ $doneBody }}</p> @endif
        </div>
    @else
        <form wire:submit="submit" class="space-y-5 rounded-2xl bg-white p-6 text-slate-900" novalidate>
            @csrf
            <fieldset>
                <legend class="text-sm font-semibold">I am a</legend>
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach ($roles as $card)
                        <label class="cursor-pointer">
                            <input type="radio" class="peer sr-only" name="role" value="{{ $card['code'] }}" wire:model.live="role">
                            <span class="inline-flex min-h-11 items-center rounded-full border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 peer-checked:border-slate-900 peer-checked:bg-slate-900 peer-checked:text-white peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-blue-600">{{ $card['label'] }}</span>
                        </label>
                    @endforeach
                </div>
                @error('role') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </fieldset>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="pl-name" class="block text-sm font-medium">Your name</label>
                    <input id="pl-name" type="text" autocomplete="name" wire:model="name" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm">
                    @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="pl-phone" class="block text-sm font-medium">Mobile number</label>
                    <input id="pl-phone" type="tel" inputmode="tel" autocomplete="tel" wire:model="phone" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm">
                    @error('phone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="pl-city" class="block text-sm font-medium">City</label>
                    <input id="pl-city" type="text" autocomplete="address-level2" wire:model="city" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm">
                    @error('city') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>

            {{-- Honeypot: hidden from people and assistive tech. --}}
            <div aria-hidden="true" style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden">
                <label for="pl-website">Leave this empty</label>
                <input id="pl-website" type="text" tabindex="-1" autocomplete="off" wire:model="website">
            </div>

            <div>
                <label class="flex items-start gap-2 text-sm text-slate-700">
                    <input type="checkbox" wire:model="consent" class="mt-1 h-4 w-4">
                    <span>{{ $consentText }}</span>
                </label>
                @error('consent') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            @if ($error)
                <p role="alert" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ $error }}</p>
            @endif

            <button type="submit" wire:loading.attr="disabled" class="inline-flex min-h-11 items-center rounded-lg bg-slate-900 px-6 py-3 text-sm font-semibold text-white hover:bg-slate-800">Apply</button>
        </form>
    @endif
</div>
