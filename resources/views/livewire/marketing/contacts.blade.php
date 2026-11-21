<div>
    <div class="mb-4">
        <h1 class="text-xl font-semibold">📇 Contacts & app invites</h1>
        <p class="text-sm text-gray-500">Your old customers who are not on the app yet. Add your list, invite them by email or WhatsApp, and see who joined.</p>
    </div>

    @if ($flashMessage)
        <div @class(['mb-4 rounded-xl px-4 py-3 text-sm', 'bg-green-50 text-green-800' => $flashType === 'success', 'bg-blue-50 text-blue-800' => $flashType !== 'success'])>{{ $flashMessage }}</div>
    @endif

    <div class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-4">
        <div class="rounded-2xl bg-sky-50 p-4 ring-1 ring-sky-100"><p class="text-sm text-sky-700">Contacts</p><p class="text-3xl font-bold text-sky-900">{{ number_format($stats['total']) }}</p></div>
        <div class="rounded-2xl bg-violet-50 p-4 ring-1 ring-violet-100"><p class="text-sm text-violet-700">With email</p><p class="text-3xl font-bold text-violet-900">{{ number_format($stats['email']) }}</p></div>
        <div class="rounded-2xl bg-amber-50 p-4 ring-1 ring-amber-100"><p class="text-sm text-amber-700">Invited</p><p class="text-3xl font-bold text-amber-900">{{ number_format($stats['invited']) }}</p></div>
        <div class="rounded-2xl bg-green-50 p-4 ring-1 ring-green-100"><p class="text-sm text-green-700">Joined the app ✓</p><p class="text-3xl font-bold text-green-900">{{ number_format($stats['joined']) }}</p></div>
    </div>

    {{-- Step 1: add the list --}}
    <div class="mb-6 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-200" data-import>
        <h2 class="mb-1 font-semibold"><span class="mr-1 rounded-full bg-sky-600 px-2 py-0.5 text-sm text-white">1</span> Add your customer list</h2>
        <p class="mb-3 text-sm text-gray-600">An Excel or CSV file with columns <b>Name</b>, <b>Mobile</b>, <b>Email</b>, <b>City</b> (any order; Email and City can be empty). Same mobile twice is added once.</p>
        @if (! $preview)
            <label class="inline-flex min-h-[44px] cursor-pointer items-center rounded-xl bg-sky-600 px-5 font-semibold text-white hover:bg-sky-700">
                📂 Choose the file <input type="file" wire:model="file" accept=".xlsx,.xls,.csv,.txt" class="hidden">
            </label>
            <span wire:loading wire:target="file" class="ml-2 text-sm text-gray-500">Reading…</span>
            @error('file') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
        @else
            <div class="rounded-xl bg-sky-50 p-3 text-sm" data-preview>
                <p class="font-semibold text-sky-900">{{ count($preview['rows']) }} contacts ready to add
                    @if ($preview['existing'])· {{ $preview['existing'] }} already in your list (their blanks will be filled)@endif
                    @if ($preview['duplicates'])· {{ $preview['duplicates'] }} repeated rows left out @endif
                </p>
                @if ($preview['problems'])
                    <ul class="mt-2 list-disc pl-5 text-amber-800">
                        @foreach (array_slice($preview['problems'], 0, 10) as $p)<li>{{ $p }}</li>@endforeach
                        @if (count($preview['problems']) > 10)<li>…and {{ count($preview['problems']) - 10 }} more rows like these.</li>@endif
                    </ul>
                @endif
                <table class="mt-3 w-full text-left text-xs">
                    <tr class="text-gray-500"><th class="py-1">Name</th><th>Mobile</th><th>Email</th><th>City</th></tr>
                    @foreach (array_slice($preview['rows'], 0, 5) as $r)
                        <tr class="border-t border-sky-100"><td class="py-1">{{ $r['name'] ?? '–' }}</td><td>{{ $r['phone'] ?? '–' }}</td><td>{{ $r['email'] ?? '–' }}</td><td>{{ $r['city'] ?? '–' }}</td></tr>
                    @endforeach
                </table>
                @if ($cities->isNotEmpty())
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <span class="text-gray-700">City for rows without one:</span>
                        <select wire:model="importCityId" class="min-h-[44px] rounded-lg border px-3"><option value="">Leave empty</option>@foreach ($cities as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select>
                    </div>
                @endif
                <div class="mt-3 flex flex-wrap gap-2">
                    <button type="button" wire:click="import" @disabled(! count($preview['rows'])) class="min-h-[44px] rounded-xl bg-sky-600 px-5 font-semibold text-white hover:bg-sky-700 disabled:opacity-40" data-confirm-import>✓ Add {{ count($preview['rows']) }} contacts</button>
                    <button type="button" wire:click="cancelImport" class="min-h-[44px] rounded-xl bg-white px-4 text-gray-600 ring-1 ring-gray-300">Cancel</button>
                </div>
            </div>
        @endif
    </div>

    {{-- Step 2: invite --}}
    <div class="mb-6 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-gray-200" data-invite>
        <h2 class="mb-1 font-semibold"><span class="mr-1 rounded-full bg-green-600 px-2 py-0.5 text-sm text-white">2</span> Invite them to the app</h2>
        <div class="mb-3 rounded-xl bg-gray-50 p-3 text-sm">
            <p class="font-semibold text-gray-800">{{ $message['title'] }}</p>
            <p class="whitespace-pre-line text-gray-700">{{ $message['body'] }}</p>
            <p class="mt-2 text-xs text-gray-500">These words are the "Invite to the new 1CallFix app" template. Change them in <a href="{{ route('admin.notifications.index') }}" class="text-blue-700 underline">Notification Center</a> → Templates tab.</p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <button type="button" wire:click="emailAll" wire:confirm="Email the app invitation to {{ $emailable }} contacts now?" @disabled($emailable === 0)
                    class="min-h-[44px] rounded-xl bg-violet-600 px-5 font-semibold text-white hover:bg-violet-700 disabled:opacity-40" data-email-all>✉️ Email {{ $emailable }} {{ \Illuminate\Support\Str::plural('contact', $emailable) }}</button>
            <span class="text-sm text-gray-600">WhatsApp: tap <b>Send on WhatsApp</b> on each contact below (opens WhatsApp with the message ready). Sending to everyone at once needs WhatsApp Business approval from Meta.</span>
        </div>
    </div>

    {{-- Step 3: the list --}}
    <div class="mb-3 flex flex-wrap items-center gap-2">
        @foreach ($filters as $key => $label)
            <button type="button" wire:click="$set('filter', '{{ $key }}')" class="min-h-[44px] rounded-full px-4 text-sm font-semibold {{ $filter === $key ? 'bg-gray-800 text-white' : 'bg-white ring-1 ring-gray-300' }}">{{ $label }}</button>
        @endforeach
        <input type="search" wire:model.live.debounce.400ms="search" placeholder="Search name, mobile, email" class="min-h-[44px] flex-1 rounded-xl border px-3 text-sm">
    </div>
    <div class="divide-y rounded-2xl bg-white shadow-sm ring-1 ring-gray-200">
        @forelse ($contacts as $c)
            @php($wa = $c->unsubscribed_at || $c->joined_user_id ? null : \App\Services\Marketing\ContactInviter::whatsappLink($c))
            <div class="flex flex-wrap items-center gap-3 px-4 py-3" data-contact="{{ $c->id }}">
                <div class="min-w-[12rem] flex-1">
                    <p class="font-medium text-gray-900">{{ $c->name ?: 'No name' }}
                        @if ($c->joined_user_id)<span class="ml-1 rounded-full bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-800">Joined ✓</span>@endif
                        @if ($c->unsubscribed_at)<span class="ml-1 rounded-full bg-gray-200 px-2 py-0.5 text-xs font-semibold text-gray-700">Unsubscribed</span>@endif
                        @if ($c->email_invited_at)<span class="ml-1 rounded-full bg-violet-100 px-2 py-0.5 text-xs font-semibold text-violet-800">✉️ {{ $c->email_invited_at->format('j M') }}</span>@endif
                        @if ($c->whatsapp_invited_at)<span class="ml-1 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800">WhatsApp {{ $c->whatsapp_invited_at->format('j M') }}</span>@endif
                    </p>
                    <p class="text-sm text-gray-500">{{ $c->phone ?? '–' }}@if ($c->email) · {{ $c->email }}@endif @if ($c->city_name) · {{ $c->city_name }}@endif</p>
                </div>
                @if ($wa)
                    <a href="{{ $wa }}" target="_blank" rel="noopener" wire:click="markWhatsapp({{ $c->id }})" class="inline-flex min-h-[44px] items-center rounded-xl bg-green-600 px-4 font-semibold text-white hover:bg-green-700" data-whatsapp>Send on WhatsApp</a>
                @endif
                <button type="button" wire:click="delete({{ $c->id }})" wire:confirm="Remove {{ $c->name ?: 'this contact' }} from the list?" class="min-h-[44px] rounded-xl px-3 text-sm text-gray-500 hover:bg-gray-100" aria-label="Remove">🗑</button>
            </div>
        @empty
            <p class="px-4 py-8 text-center text-sm text-gray-500">No contacts here yet. Add your list in step 1.</p>
        @endforelse
    </div>
    <div class="mt-3">{{ $contacts->links() }}</div>
</div>
