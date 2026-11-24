<div>
    @assets
        <script src="{{ asset('js/social-share.js') }}?v={{ config('app.version') }}" defer></script>
    @endassets
    @php
        $modColour = ['service' => 'bg-blue-100 text-blue-800', 'parcel' => 'bg-orange-100 text-orange-800', 'shop' => 'bg-violet-100 text-violet-800',
            'food' => 'bg-rose-100 text-rose-800', 'rental' => 'bg-teal-100 text-teal-800', 'hotel' => 'bg-cyan-100 text-cyan-800',
            'events' => 'bg-fuchsia-100 text-fuchsia-800', 'listings' => 'bg-lime-100 text-lime-800', 'taxi' => 'bg-amber-100 text-amber-800'];
        $statusColour = ['draft' => 'bg-gray-100 text-gray-700', 'approval' => 'bg-purple-100 text-purple-800', 'scheduled' => 'bg-blue-100 text-blue-800', 'posted' => 'bg-green-100 text-green-800', 'skipped' => 'bg-amber-100 text-amber-800'];
        $statuses = \App\Models\SocialPost::STATUSES;
    @endphp

    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold">📣 Social posts</h1>
            <p class="text-sm text-gray-500">Plan a post a day for Facebook, Instagram, Google Business and WhatsApp. On the day, tap <b>Share</b>, then <b>Done ✓</b>. Posts for a switched-off module are skipped by themselves.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="$toggle('showIdeas')" class="inline-flex min-h-[44px] items-center rounded-xl bg-amber-100 px-4 font-semibold text-amber-900 hover:bg-amber-200" data-ideas-toggle>💡 Ready-made ideas</button>
            <button type="button" wire:click="fillWeek" class="inline-flex min-h-[44px] items-center rounded-xl bg-indigo-100 px-4 font-semibold text-indigo-800 hover:bg-indigo-200" data-fill-week>🗓️ Fill my next 7 days</button>
            <button type="button" wire:click="newPost" class="inline-flex min-h-[44px] items-center rounded-xl bg-blue-600 px-5 font-semibold text-white hover:bg-blue-700" data-new-post>＋ New post</button>
        </div>
    </div>

    @if ($flashMessage)
        <div @class(['mb-4 rounded-xl px-4 py-3 text-sm', 'bg-green-50 text-green-800' => $flashType === 'success', 'bg-red-50 text-red-700' => $flashType === 'error', 'bg-blue-50 text-blue-800' => $flashType === 'info'])>{{ $flashMessage }}</div>
    @endif

    {{-- HQ approval (owner 2026-11-22): franchise / city posts go on the main 1CallFix pages only after HQ approves. --}}
    @if ($isHq && $awaiting->isNotEmpty())
        <div class="mb-6 rounded-2xl bg-purple-50 p-4 ring-2 ring-purple-200" data-awaiting-approval>
            <h2 class="mb-3 font-semibold text-purple-900">⏳ Waiting for your approval ({{ $awaiting->count() }})</h2>
            <div class="space-y-3">
                @foreach ($awaiting as $p)
                    <div class="flex flex-col gap-3 rounded-xl bg-white p-3 ring-1 ring-purple-100 md:flex-row" wire:key="await-{{ $p->id }}">
                        @if ($p->imageUrl())<img src="{{ url($p->imageUrl()) }}" alt="" class="h-32 w-full rounded-lg object-cover md:w-44">@endif
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold">{{ $p->title }} <span class="text-xs font-normal text-gray-500">· {{ $p->city?->name ?? 'All cities' }} · {{ $p->scheduled_for?->setTimezone($zone)->format('D j M, h:i A') }}</span></p>
                            <p class="mt-1 whitespace-pre-line text-sm text-gray-700">{{ \Illuminate\Support\Str::limit($p->caption_en, 300) }}</p>
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <button type="button" wire:click="approve({{ $p->id }})" class="min-h-[44px] rounded-xl bg-green-600 px-5 text-sm font-semibold text-white hover:bg-green-700" data-approve="{{ $p->id }}">✓ Approve</button>
                                <button type="button" wire:click="edit({{ $p->id }})" class="min-h-[44px] rounded-xl bg-blue-50 px-4 text-sm font-semibold text-blue-700">✏️ Edit</button>
                                <input type="text" wire:model="sendBackNotes.{{ $p->id }}" placeholder="What to change (to send back)" class="min-h-[44px] min-w-[12rem] flex-1 rounded-lg border px-2 text-sm">
                                <button type="button" wire:click="sendBack({{ $p->id }})" class="min-h-[44px] rounded-xl bg-white px-4 text-sm text-gray-700 ring-1 ring-gray-300">↩ Send back</button>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @elseif (! $isHq && ($awaiting->isNotEmpty() || $sentBack->isNotEmpty()))
        <div class="mb-6 rounded-2xl bg-purple-50 p-4 ring-1 ring-purple-200" data-my-approvals>
            <p class="text-sm text-purple-900">Your posts go on the main 1CallFix pages after HQ approves them.</p>
            @foreach ($awaiting as $p)
                <p class="mt-1 text-sm">⏳ <b>{{ $p->title }}</b>: waiting for HQ approval</p>
            @endforeach
            @foreach ($sentBack as $p)
                <p class="mt-1 text-sm">↩ <b>{{ $p->title }}</b>: {{ $p->skip_reason }} <button type="button" wire:click="edit({{ $p->id }})" class="ml-1 text-blue-700 underline">Open</button></p>
            @endforeach
        </div>
    @endif

    {{-- Ready-made ideas --}}
    @if ($showIdeas)
        <div class="mb-6 rounded-2xl bg-amber-50 p-4 ring-1 ring-amber-200" data-ideas>
            <h2 class="mb-1 font-semibold text-amber-900">💡 Ready-made ideas from your live site</h2>
            <p class="mb-3 text-sm text-amber-800">Banners, offers, what people looked at most, new shops, jobs. Switched-off modules are left out. Tap <b>Use this</b>, check it, pick the day.</p>
            <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-3">
                @forelse ($ideas as $i => $idea)
                    @php($ideaImg = $idea['image_path'] ? (\Illuminate\Support\Str::startsWith($idea['image_path'], ['http', '/']) ? $idea['image_path'] : \Illuminate\Support\Facades\Storage::disk('public')->url($idea['image_path'])) : null)
                    <div class="flex flex-col overflow-hidden rounded-xl bg-white ring-1 ring-amber-200" data-idea>
                        @if ($ideaImg)<img src="{{ $ideaImg }}" alt="" class="h-28 w-full object-cover">@endif
                        <div class="flex flex-1 flex-col p-3">
                            <p class="mb-1 flex flex-wrap gap-1 text-xs">
                                <span class="rounded-full bg-amber-100 px-2 py-0.5 font-semibold text-amber-800">{{ $idea['badge'] }}</span>
                                @if ($idea['module'])<span class="rounded-full px-2 py-0.5 font-semibold {{ $modColour[$idea['module']] ?? 'bg-gray-100' }}">{{ $moduleLabels[$idea['module']] ?? $idea['module'] }}</span>@endif
                            </p>
                            <p class="flex-1 text-sm text-gray-800">{{ $idea['caption_en'] }}</p>
                            <button type="button" wire:click="useIdea({{ $i }})" class="mt-2 min-h-[44px] rounded-xl bg-amber-500 px-4 font-semibold text-white hover:bg-amber-600">Use this</button>
                            @if ($shortsOn)
                                @include('livewire.marketing.partials.shorts-prompt', ['shorts' => \App\Services\Marketing\ShortVideoPrompt::forIdea($idea, $ideaCity)])
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-amber-800">No new ideas right now (each idea is offered once every 14 days). Write your own with <b>＋ New post</b>.</p>
                @endforelse
            </div>
        </div>
    @endif

    {{-- Editor --}}
    @if ($editing)
        <div class="mb-6 rounded-2xl bg-white shadow-sm ring-2 ring-blue-300" data-editor>
            <div class="flex items-center justify-between border-b px-4 py-3">
                <h2 class="font-semibold">{{ $editId ? '✏️ Edit post' : '＋ New post' }}</h2>
                <button type="button" wire:click="closeEditor" class="min-h-[44px] rounded-xl px-3 text-sm text-gray-600 hover:bg-gray-100">✕ Close</button>
            </div>
            <div class="space-y-5 p-4">
                <div class="rounded-xl bg-sky-50 p-3">
                    <p class="mb-2 text-sm font-bold text-sky-800">1 · Picture</p>
                    <div class="flex flex-wrap items-center gap-3">
                        @php($preview = $upload ? $upload->temporaryUrl() : ($imagePath ? (\Illuminate\Support\Str::startsWith($imagePath, ['http', '/']) ? $imagePath : \Illuminate\Support\Facades\Storage::disk('public')->url($imagePath)) : null))
                        @if ($preview)<img src="{{ $preview }}" alt="" class="h-24 w-40 rounded-lg object-cover ring-1 ring-sky-200">@endif
                        <label class="inline-flex min-h-[44px] cursor-pointer items-center rounded-xl bg-white px-4 text-sm font-semibold text-sky-800 ring-1 ring-sky-300 hover:bg-sky-100">
                            📷 {{ $preview ? 'Change picture' : 'Add a picture' }} <input type="file" wire:model="upload" accept="image/*" class="hidden">
                        </label>
                    </div>
                    <x-ui.image-size use="social_post" />
                    <p class="text-xs text-gray-500">Optional for Facebook and Google; Instagram needs one.</p>
                    @error('upload') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="rounded-xl bg-violet-50 p-3">
                    <p class="mb-2 text-sm font-bold text-violet-800">2 · Words</p>
                    <input type="text" wire:model="title" placeholder="Short name for the team, e.g. AC service offer" class="mb-2 min-h-[44px] w-full rounded-lg border px-3 text-sm">
                    @error('title') <p class="mb-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    <textarea wire:model="captionEn" rows="3" placeholder="The post in English" class="mb-2 w-full rounded-lg border px-3 py-2 text-sm"></textarea>
                    @error('captionEn') <p class="mb-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    <textarea wire:model="captionTe" rows="2" placeholder="తెలుగులో (optional)" class="mb-2 w-full rounded-lg border px-3 py-2 text-sm"></textarea>
                    <input type="text" wire:model="hashtags" placeholder="#1CallFix #Nellore" class="min-h-[44px] w-full rounded-lg border px-3 text-sm">
                </div>

                <div class="rounded-xl bg-teal-50 p-3">
                    <p class="mb-2 text-sm font-bold text-teal-800">3 · What it is about (optional)</p>
                    <div class="mb-2 flex flex-wrap gap-2">
                        <button type="button" wire:click="$set('module', '')" class="min-h-[44px] rounded-full px-4 text-sm font-semibold {{ $module === '' ? 'bg-gray-800 text-white' : 'bg-white ring-1 ring-gray-300' }}">Whole app</button>
                        @foreach ($moduleLabels as $key => $label)
                            <button type="button" wire:click="$set('module', '{{ $key }}')" class="min-h-[44px] rounded-full px-4 text-sm font-semibold {{ $module === $key ? 'ring-2 ring-teal-600 '.($modColour[$key] ?? '') : 'bg-white ring-1 ring-gray-300' }}">{{ $label }}</button>
                        @endforeach
                    </div>
                    @error('module') <p class="mb-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    <div class="flex flex-wrap gap-2">
                        <select wire:model="cityId" class="min-h-[44px] rounded-lg border px-3 text-sm" aria-label="City">
                            @if ($isSuperAdmin)<option value="">All cities</option>@endif
                            @foreach ($cities as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                        </select>
                        <input type="text" wire:model="linkUrl" placeholder="Link, e.g. /nellore/services (optional)" class="min-h-[44px] flex-1 rounded-lg border px-3 text-sm">
                    </div>
                    @error('linkUrl') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="rounded-xl bg-pink-50 p-3">
                    <p class="mb-2 text-sm font-bold text-pink-800">4 · Where to post</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach (array_intersect_key($platformMeta, array_flip($editorPlatforms)) as $key => [$label, $colour])
                            <button type="button" wire:click="togglePlatform('{{ $key }}')" class="min-h-[44px] rounded-full px-4 text-sm font-semibold {{ in_array($key, $platforms, true) ? $colour : 'bg-white text-gray-600 ring-1 ring-gray-300' }}">{{ in_array($key, $platforms, true) ? '✓ ' : '' }}{{ $label }}</button>
                        @endforeach
                    </div>
                    @error('platforms') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="rounded-xl bg-emerald-50 p-3">
                    <p class="mb-2 text-sm font-bold text-emerald-800">5 · When</p>
                    <div class="flex flex-wrap items-center gap-2">
                        @php($todayDate = now($zone)->toDateString())
                        @foreach (['today' => [$todayDate, 'Today'], 'tomorrow' => [now($zone)->addDay()->toDateString(), 'Tomorrow']] as $which => [$date, $dayLabel])
                            <button type="button" wire:click="pickDay('{{ $which }}')" @class(['min-h-[44px] rounded-full px-4 text-sm font-semibold',
                                'bg-emerald-600 text-white' => $day === $date, 'bg-white ring-1 ring-emerald-300' => $day !== $date])>{{ $day === $date ? '✓ ' : '' }}{{ $dayLabel }}</button>
                        @endforeach
                        <input type="date" wire:model.live="day" class="min-h-[44px] rounded-lg border px-3 text-sm" aria-label="Day">
                    </div>
                    <div class="mt-2 flex flex-wrap items-center gap-2" data-time-chips>
                        @foreach (\App\Livewire\Marketing\SocialPosts::TIME_CHIPS as $t => $timeLabel)
                            <button type="button" wire:click="pickTime('{{ $t }}')" @class(['min-h-[44px] rounded-full px-4 text-sm font-semibold',
                                'bg-emerald-600 text-white' => $time === $t, 'bg-white ring-1 ring-emerald-300' => $time !== $t])>{{ $timeLabel }}</button>
                        @endforeach
                        <label class="flex items-center gap-1 text-sm text-gray-600">or <input type="time" wire:model.live="time" class="min-h-[44px] rounded-lg border px-3 text-sm" aria-label="Time"></label>
                    </div>
                    @if ($isHq)<p class="mt-2 text-xs text-gray-600">Want it out right away? Press <b>🚀 Post now</b> below.</p>@endif
                    @error('day') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="sticky bottom-0 flex flex-wrap justify-end gap-2 rounded-b-2xl border-t bg-white px-4 py-3">
                <button type="button" wire:click="save(false)" class="min-h-[44px] rounded-xl bg-gray-100 px-5 font-semibold text-gray-800 hover:bg-gray-200">Save as draft</button>
                <button type="button" wire:click="save(true)" class="min-h-[44px] rounded-xl bg-blue-600 px-6 font-semibold text-white hover:bg-blue-700" data-schedule>📅 Schedule</button>
                @if ($isHq)
                    <button type="button" wire:click="save(true, true)" wire:loading.attr="disabled" wire:confirm="Post this everywhere now?" class="min-h-[44px] rounded-xl bg-emerald-600 px-6 font-semibold text-white hover:bg-emerald-700" data-post-now>🚀 Post now</button>
                @endif
            </div>
        </div>
    @endif

    {{-- Today --}}
    <h2 class="mb-2 text-sm font-bold uppercase tracking-wide text-emerald-700">📤 Today · {{ $today->format('l, j M') }}</h2>
    <div class="mb-6 space-y-3">
        @forelse ($todayPosts->concat($missed) as $post)
            @include('livewire.marketing.partials.share-card', ['post' => $post, 'late' => $missed->contains('id', $post->id)])
        @empty
            <div class="rounded-2xl bg-white p-6 text-center text-sm text-gray-500 ring-1 ring-gray-200">Nothing to share today. Tap <b>🗓️ Fill my next 7 days</b> or <b>＋ New post</b>.</div>
        @endforelse
    </div>

    {{-- Next two weeks --}}
    <div class="mb-2 flex flex-wrap items-center gap-2">
        <h2 class="mr-2 text-sm font-bold uppercase tracking-wide text-indigo-700">🗓️ Plan</h2>
        <button type="button" wire:click="$set('week', {{ max(0, $week - 2) }})" @disabled($week === 0) class="min-h-[44px] rounded-full bg-indigo-50 px-4 text-sm font-semibold text-indigo-700 disabled:opacity-40">‹ Earlier</button>
        <button type="button" wire:click="$set('week', {{ $week + 2 }})" class="min-h-[44px] rounded-full bg-indigo-50 px-4 text-sm font-semibold text-indigo-700">Later ›</button>
    </div>
    <div class="mb-6 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-7">
        @foreach ($days as $d)
            @php($list = $upcoming->get($d->toDateString(), collect()))
            <div class="min-h-[7rem] rounded-xl bg-white p-2 ring-1 {{ $d->isSameDay($today) ? 'ring-2 ring-emerald-400' : 'ring-gray-200' }}" data-day="{{ $d->toDateString() }}">
                <p class="mb-1 text-xs font-bold {{ $d->isWeekend() ? 'text-rose-600' : 'text-gray-500' }}">{{ $d->format('D j M') }}</p>
                @foreach ($list as $p)
                    <button type="button" wire:click="edit({{ $p->id }})" class="mb-1 block w-full rounded-lg px-2 py-1.5 text-left text-xs {{ $p->module ? ($modColour[$p->module] ?? 'bg-gray-100') : 'bg-gray-100 text-gray-800' }}" data-plan-post="{{ $p->id }}">
                        <span class="font-semibold">{{ $p->scheduled_for->setTimezone($zone)->format('h:i A') }}</span> {{ \Illuminate\Support\Str::limit($p->title, 40) }}
                        <span class="mt-0.5 block"><span class="rounded-full px-1.5 py-0.5 text-[10px] font-semibold {{ $statusColour[$p->status] }}">{{ $statuses[$p->status] }}</span></span>
                        @if ($p->skip_reason)<span class="mt-0.5 block text-[11px] text-amber-800">{{ $p->skip_reason }}</span>@endif
                    </button>
                @endforeach
                <button type="button" wire:click="newPostOn('{{ $d->toDateString() }}')" aria-label="New post on {{ $d->format('j M') }}" class="mt-1 w-full rounded-lg border border-dashed border-gray-300 py-1 text-xs text-gray-500 hover:bg-gray-50">＋</button>
            </div>
        @endforeach
    </div>

    @if ($unscheduled->isNotEmpty())
        <h2 class="mb-2 text-sm font-bold uppercase tracking-wide text-gray-600">📝 Drafts without a day</h2>
        <div class="mb-6 divide-y rounded-2xl bg-white ring-1 ring-gray-200">
            @foreach ($unscheduled as $p)
                <div class="flex flex-wrap items-center gap-2 px-4 py-2">
                    <span class="min-w-0 flex-1 truncate text-sm">{{ $p->title }}</span>
                    <button type="button" wire:click="edit({{ $p->id }})" class="min-h-[44px] rounded-xl bg-blue-50 px-4 text-sm font-semibold text-blue-700">Open</button>
                    <button type="button" wire:click="delete({{ $p->id }})" wire:confirm="Delete this draft?" class="min-h-[44px] rounded-xl bg-gray-50 px-4 text-sm text-gray-600">Delete</button>
                </div>
            @endforeach
        </div>
    @endif

    @if ($isSuperAdmin)
        <div class="mb-6 rounded-2xl bg-gray-50 p-4 ring-1 ring-gray-200" data-marketing-settings>
            <h2 class="mb-3 font-semibold">⚙️ Settings</h2>
            <p class="mb-1 text-sm text-gray-700">Places you post (new posts start with these):</p>
            <div class="mb-3 flex flex-wrap gap-2">
                @foreach (array_intersect_key($platformMeta, array_flip($editorPlatforms)) as $key => [$label, $colour])
                    <button type="button" wire:click="toggleDefaultPlatform('{{ $key }}')" class="min-h-[44px] rounded-full px-4 text-sm font-semibold {{ in_array($key, $defaultPlatforms, true) ? $colour : 'bg-white text-gray-600 ring-1 ring-gray-300' }}">{{ in_array($key, $defaultPlatforms, true) ? '✓ ' : '' }}{{ $label }}</button>
                @endforeach
            </div>
            <div class="mb-3 rounded-xl bg-red-50 p-3 ring-1 ring-red-200" data-shorts-settings>
                <p class="mb-2 text-sm font-semibold text-red-800">🎬 YouTube Short prompts <span class="font-normal text-red-700">(a 🎬 button on each post and idea: words to paste in YouTube's AI video, plus our ending in English and తెలుగు)</span></p>
                <div class="mb-2 flex flex-wrap gap-2">
                    <button type="button" wire:click="setShorts(true)" class="min-h-[44px] rounded-full px-4 text-sm font-semibold {{ $shortsOn ? 'bg-green-600 text-white' : 'bg-white ring-1 ring-gray-300' }}" data-shorts-on>{{ $shortsOn ? '✓ ' : '' }}On</button>
                    <button type="button" wire:click="setShorts(false)" class="min-h-[44px] rounded-full px-4 text-sm font-semibold {{ ! $shortsOn ? 'bg-gray-800 text-white' : 'bg-white ring-1 ring-gray-300' }}" data-shorts-off>{{ ! $shortsOn ? '✓ ' : '' }}Off</button>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm text-gray-700">Presenter:</span>
                    @foreach (\App\Services\Marketing\ShortVideoPrompt::PRESENTERS as $who => $whoLabel)
                        <button type="button" wire:click="setShortsPresenter('{{ $who }}')" class="min-h-[44px] rounded-full px-4 text-sm font-semibold {{ $shortsPresenter === $who ? 'bg-red-600 text-white' : 'bg-white ring-1 ring-gray-300' }}">{{ $whoLabel }}</button>
                    @endforeach
                </div>
            </div>
            @foreach (['post' => ['New posts go out at', ['09:00', '10:00', '12:00', '18:00', '19:30'], $postTime], 'reminder' => ['Remind the team at', ['08:00', '09:30', '11:00'], $reminderTime]] as $which => [$label, $choices, $current])
                <div class="mb-2 flex flex-wrap items-center gap-2">
                    <span class="min-w-[11rem] text-sm text-gray-700">{{ $label }}</span>
                    @foreach ($choices as $t)
                        <button type="button" wire:click="setTime('{{ $which }}', '{{ $t }}')" class="min-h-[44px] rounded-full px-4 text-sm font-semibold {{ $current === $t ? 'bg-gray-800 text-white' : 'bg-white ring-1 ring-gray-300' }}">{{ \Carbon\Carbon::createFromFormat('H:i', $t)->format('g:i A') }}</button>
                    @endforeach
                </div>
            @endforeach
            <p class="mt-2 text-xs text-gray-500">Choose below how each place posts: Direct (our Facebook connection), Buffer, or By hand. WhatsApp Status is always one tap.</p>
        </div>
        <livewire:marketing.posting-routes />
        <livewire:marketing.buffer-connect />
        <livewire:marketing.meta-connect />
    @endif
</div>
