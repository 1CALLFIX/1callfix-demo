{{-- Connect Facebook & Instagram (owner 2026-11-22): Super Admin only, three numbered steps. --}}
<div class="mb-6 rounded-2xl bg-white p-4 ring-2 ring-blue-200" data-meta-connect>
    <div class="mb-3 flex flex-wrap items-center gap-2">
        <h2 class="font-semibold text-blue-900">🔗 Post automatically to Facebook &amp; Instagram</h2>
        @if ($connected)
            <span class="rounded-full bg-green-100 px-3 py-0.5 text-xs font-semibold text-green-800">Connected</span>
        @elseif ($broken)
            <span class="rounded-full bg-red-100 px-3 py-0.5 text-xs font-semibold text-red-800">Needs Connect again</span>
        @else
            <span class="rounded-full bg-gray-100 px-3 py-0.5 text-xs font-semibold text-gray-700">Not connected</span>
        @endif
    </div>

    @if ($flash || $message)
        <p class="mb-3 rounded-lg bg-blue-50 p-3 text-sm text-blue-900">{{ $message ?: $flash }}</p>
    @endif

    {{-- 1 · Meta app --}}
    <div class="mb-3 rounded-xl bg-indigo-50/60 p-3">
        <p class="text-sm font-semibold text-indigo-900">1 · Your Meta app</p>
        <p class="mt-1 text-xs text-gray-600">developers.facebook.com → My Apps → <b>1CALLFix</b> → App settings → Basic. The secret is saved locked and never shown again.</p>
        <div class="mt-2 flex flex-wrap items-start gap-2">
            <div>
                <input type="text" inputmode="numeric" wire:model="appId" placeholder="App ID" class="min-h-11 w-48 rounded-lg border px-2 text-sm">
                @error('appId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <input type="password" autocomplete="off" wire:model="appSecret" placeholder="{{ $hasSecret ? 'App secret (saved ✓, type to change)' : 'App secret' }}" class="min-h-11 w-72 rounded-lg border px-2 text-sm">
                @error('appSecret') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <button type="button" wire:click="saveApp" class="min-h-11 rounded-xl bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">Save</button>
        </div>
        <p class="mt-2 text-xs text-gray-500">In the Meta app, Facebook Login → Settings → <b>Valid OAuth Redirect URIs</b> must have: <code class="rounded bg-white px-1">{{ $redirectUri }}</code></p>
    </div>

    {{-- 2 · Connect --}}
    <div class="mb-3 rounded-xl bg-blue-50/60 p-3">
        <p class="text-sm font-semibold text-blue-900">2 · Connect</p>
        @if ($connected)
            <p class="mt-1 text-sm text-gray-800">Posting to <b>{{ $pageName }}</b>{!! $igUsername ? ' and Instagram <b>@'.e($igUsername).'</b>' : ' <span class="text-amber-700">(no Instagram linked to this Page)</span>' !!}.</p>
        @elseif ($broken)
            <p class="mt-1 text-sm text-red-700">Facebook stopped accepting the connection ({{ $broken }}). Press Connect again.</p>
        @else
            <p class="mt-1 text-xs text-gray-600">Opens Facebook: approve once and tick the 1CallFix Page and its Instagram.</p>
        @endif

        @if ($choices !== [])
            <p class="mt-2 text-sm font-medium">Which Page should we post to?</p>
            <div class="mt-1 flex flex-wrap gap-2">
                @foreach ($choices as $i => $choice)
                    <button type="button" wire:click="choosePage({{ $i }})" class="min-h-11 rounded-full bg-white px-4 text-sm font-semibold ring-1 ring-blue-300 hover:bg-blue-50">{{ $choice['name'] }}{{ $choice['ig'] ? ' + @'.$choice['ig'] : '' }}</button>
                @endforeach
            </div>
        @endif

        <div class="mt-2 flex flex-wrap gap-2">
            @if ($hasApp)
                <a href="{{ route('admin.marketing.meta.connect') }}" class="inline-flex min-h-11 items-center rounded-xl bg-blue-600 px-5 text-sm font-semibold text-white hover:bg-blue-700" data-meta-connect-button>{{ $connected ? 'Connect again' : 'Connect Facebook & Instagram' }}</a>
            @else
                <span class="inline-flex min-h-11 items-center rounded-xl bg-gray-200 px-5 text-sm font-semibold text-gray-500">Connect (save step 1 first)</span>
            @endif
            @if ($connected)
                <button type="button" wire:click="disconnect" wire:confirm="Stop posting automatically to Facebook and Instagram?" class="min-h-11 rounded-xl bg-white px-4 text-sm text-gray-600 ring-1 ring-gray-300">Disconnect</button>
            @endif
        </div>
    </div>

    {{-- 3 · On / off --}}
    <div class="rounded-xl bg-emerald-50/60 p-3">
        <p class="text-sm font-semibold text-emerald-900">3 · Automatic posting</p>
        <div class="mt-1 flex flex-wrap items-center gap-2">
            <button type="button" wire:click="toggleAutopost" @class(['min-h-11 rounded-full px-4 text-sm font-semibold',
                'bg-emerald-600 text-white' => $autopost, 'bg-white text-gray-700 ring-1 ring-gray-300' => ! $autopost])>{{ $autopost ? '✓ On' : 'Off' }}</button>
            <span class="text-xs text-gray-600">On: scheduled posts go out on Facebook and Instagram at their time by themselves. Google Business and WhatsApp Status stay one tap.</span>
        </div>
    </div>
</div>
