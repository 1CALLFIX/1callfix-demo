{{-- 🎬 YouTube Short (owner 2026-11-24): copy-ready prompt for YouTube's own AI video + our 1CallFix ending.
     $shorts = ShortVideoPrompt::forPost() / forIdea(): ['presenter', 'en', 'te' (null when the post has no Telugu words)]. --}}
<div x-data="{ open: false, lang: 'en', copied: false,
        copy(el) { const done = () => { this.copied = true; setTimeout(() => this.copied = false, 2500) };
            if (navigator.clipboard) { navigator.clipboard.writeText(el.value).then(done) } else { el.select(); document.execCommand('copy'); done() } } }"
     class="mt-2" data-shorts>
    <button type="button" @click="open = !open" class="inline-flex min-h-[44px] items-center gap-1 rounded-xl bg-red-50 px-4 text-sm font-semibold text-red-700 ring-1 ring-red-200 hover:bg-red-100" data-shorts-open>
        🎬 YouTube Short
    </button>
    <div x-show="open" style="display: none" class="mt-2 space-y-3 rounded-xl bg-white p-3 text-sm ring-2 ring-red-200">
        <div class="rounded-lg bg-rose-50 p-2">
            <p class="mb-2 font-bold text-rose-800">1 · Voice</p>
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" @click="lang = 'en'" :class="lang === 'en' ? 'bg-rose-600 text-white' : 'bg-white ring-1 ring-red-200'" class="min-h-[44px] rounded-full px-4 font-semibold">English</button>
                @if ($shorts['te'])
                    <button type="button" @click="lang = 'te'" :class="lang === 'te' ? 'bg-rose-600 text-white' : 'bg-white ring-1 ring-red-200'" class="min-h-[44px] rounded-full px-4 font-semibold" data-shorts-te>తెలుగు</button>
                @endif
                <span class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-rose-800 ring-1 ring-red-200">{{ $shorts['presenter'] === 'woman' ? '👩 Woman presenter' : '👨 Man presenter' }}</span>
            </div>
        </div>
        <div class="rounded-lg bg-amber-50 p-2">
            <p class="mb-2 font-bold text-amber-800">2 · Copy the words</p>
            <textarea x-ref="en" x-show="lang === 'en'" rows="5" class="w-full rounded-lg border px-2 py-1 text-xs" data-shorts-prompt="en">{{ $shorts['en'] }}</textarea>
            @if ($shorts['te'])
                <textarea x-ref="te" x-show="lang === 'te'" style="display: none" rows="5" class="w-full rounded-lg border px-2 py-1 text-xs" data-shorts-prompt="te">{{ $shorts['te'] }}</textarea>
            @endif
            <button type="button" @click="copy(lang === 'te' && $refs.te ? $refs.te : $refs.en)" class="mt-1 min-h-[44px] rounded-xl bg-amber-500 px-5 font-semibold text-white hover:bg-amber-600">
                <span x-show="!copied">📋 Copy</span><span x-show="copied" style="display: none">✓ Copied</span>
            </button>
        </div>
        <div class="rounded-lg bg-sky-50 p-2">
            <p class="mb-1 font-bold text-sky-800">3 · Make it in the YouTube app</p>
            <p class="text-sky-900">＋ → <b>Create a Short</b> → ✨ <b>AI video</b> → paste → create.</p>
        </div>
        <div class="rounded-lg bg-emerald-50 p-2">
            <p class="mb-2 font-bold text-emerald-800">4 · Add our ending after it</p>
            <div class="flex flex-wrap gap-2">
                <a href="{{ asset(\App\Services\Marketing\ShortVideoPrompt::ENDINGS['en']) }}" download class="inline-flex min-h-[44px] items-center rounded-xl bg-white px-4 font-semibold text-emerald-800 ring-1 ring-emerald-300 hover:bg-emerald-100" data-shorts-ending="en">⬇️ English ending</a>
                <a href="{{ asset(\App\Services\Marketing\ShortVideoPrompt::ENDINGS['te']) }}" download class="inline-flex min-h-[44px] items-center rounded-xl bg-white px-4 font-semibold text-emerald-800 ring-1 ring-emerald-300 hover:bg-emerald-100" data-shorts-ending="te">⬇️ తెలుగు ending</a>
            </div>
        </div>
        <p class="text-xs text-gray-500">YouTube marks AI videos by itself. The person in the video is a presenter, never a customer.</p>
    </div>
</div>
