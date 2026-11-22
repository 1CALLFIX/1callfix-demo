{{-- One post to share today (Social posts). Share = phone share sheet / computer copy + download + open; then Done ✓. --}}
@php($img = $post->imageUrl() ? url($post->imageUrl()) : null)
<div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 {{ $late ? 'ring-amber-300' : 'ring-emerald-200' }}" data-share-card="{{ $post->id }}">
    <div class="flex flex-col gap-4 p-4 md:flex-row">
        @if ($img)
            <img src="{{ $img }}" alt="" class="h-40 w-full rounded-xl object-cover md:h-32 md:w-48">
        @endif
        <div class="min-w-0 flex-1">
            <p class="mb-1 flex flex-wrap items-center gap-2">
                <span class="font-semibold text-gray-900">{{ $post->title }}</span>
                @if ($post->module)<span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $modColour[$post->module] ?? 'bg-gray-100' }}">{{ $moduleLabels[$post->module] ?? $post->module }}</span>@endif
                <span class="text-xs text-gray-500">{{ $post->scheduled_for->setTimezone($zone)->format($late ? 'D j M, h:i A' : 'h:i A') }}</span>
                @if ($late)<span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">Not shared yet</span>@endif
            </p>
            <p class="whitespace-pre-line text-sm text-gray-700">{{ \Illuminate\Support\Str::limit($post->caption_en, 220) }}</p>
            <div class="mt-3 grid gap-2 sm:grid-cols-2">
                @foreach ($post->platforms ?? [] as $platform)
                    @php([$label, $colour, $openUrl] = $platformMeta[$platform] ?? [$platform, 'bg-gray-600 text-white', null])
                    @if ($post->isPostedOn($platform))
                        <div class="flex min-h-[44px] items-center justify-between rounded-xl bg-green-50 px-3 text-sm font-semibold text-green-800">
                            <span>✓ Posted on {{ $label }}</span>
                            <button type="button" wire:click="undoDone({{ $post->id }}, '{{ $platform }}')" class="text-xs font-normal text-green-700 underline">undo</button>
                        </div>
                    @else
                        @php($delivery = $post->deliveries->firstWhere('platform', $platform))
                        @if (in_array($platform, $autoPlatforms, true))
                            {{-- Connected: goes out by itself at its time; Post now / Try again for the impatient or a failure. --}}
                            <div class="mb-1 rounded-xl p-2 text-sm {{ $delivery?->status === 'failed' ? 'bg-red-50 text-red-800 ring-1 ring-red-200' : 'bg-blue-50 text-blue-900' }}" data-auto="{{ $platform }}">
                                @if ($delivery?->status === 'failed')
                                    <p class="font-semibold">⚠️ Could not post on {{ $label }}</p>
                                    <p class="text-xs">{{ $delivery->error }}</p>
                                @elseif ($delivery?->status === 'posting')
                                    <p class="font-semibold">⏳ Posting on {{ $label }}…</p>
                                @else
                                    <p class="font-semibold">🤖 Posts by itself on {{ $label }} at {{ $post->scheduled_for?->setTimezone($zone)->format('g:i A') }}</p>
                                @endif
                                <button type="button" wire:click="postNow({{ $post->id }}, '{{ $platform }}')" wire:loading.attr="disabled" wire:confirm="Post this on {{ $label }} now?"
                                        class="mt-1 min-h-[40px] rounded-lg bg-white px-3 text-xs font-semibold ring-1 ring-current">{{ $delivery?->status === 'failed' ? 'Try again' : 'Post now' }}</button>
                            </div>
                        @endif
                        <div class="rounded-xl bg-gray-50 p-2 ring-1 ring-gray-200">
                            <div class="flex gap-2">
                                <button type="button" class="min-h-[44px] flex-1 rounded-xl px-3 text-sm font-semibold {{ $colour }}"
                                        data-share data-share-text="{{ $post->shareText($platform) }}" data-share-image="{{ $img }}" data-share-open="{{ $openUrl }}" data-share-platform="{{ $platform }}">
                                    📤 Share on {{ $label }}
                                </button>
                                <button type="button" wire:click="markDone({{ $post->id }}, '{{ $platform }}')" class="min-h-[44px] rounded-xl bg-white px-3 text-sm font-semibold text-green-700 ring-1 ring-green-300 hover:bg-green-50" data-done="{{ $platform }}">Done ✓</button>
                            </div>
                            <input type="url" wire:model="postUrls.{{ $post->id }}-{{ $platform }}" placeholder="Post link (optional)" class="mt-2 min-h-[40px] w-full rounded-lg border px-2 text-xs">
                        </div>
                    @endif
                @endforeach
            </div>
            <div class="mt-3 flex flex-wrap gap-2 text-sm">
                <button type="button" wire:click="edit({{ $post->id }})" class="min-h-[44px] rounded-xl bg-blue-50 px-4 font-semibold text-blue-700">✏️ Edit</button>
                <button type="button" wire:click="skip({{ $post->id }})" wire:confirm="Skip this post?" class="min-h-[44px] rounded-xl bg-gray-50 px-4 text-gray-600">Skip</button>
            </div>
        </div>
    </div>
</div>
