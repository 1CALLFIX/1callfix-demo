{{-- 📣 Share my business (owner 2026-11-22): on every partner dashboard, the partner's own live pages with picture and
     ready words; one tap opens the phone's share sheet (WhatsApp, Facebook, Instagram…). Nothing shows until a page is
     live. Uses public/js/social-share.js (plain file, no build); classes are ones already in the built CSS. --}}
@php $businesses = \App\Services\Marketing\PartnerBusinesses::for(auth()->user()); @endphp
@if ($businesses !== [])
    @once
        <script src="{{ asset('js/social-share.js') }}?v={{ config('app.version') }}" defer data-navigate-once></script>
    @endonce
    <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-4" data-share-my-business>
        <h2 class="text-base font-bold text-slate-900">📣 Share your business</h2>
        <p class="mt-1 text-sm text-slate-600">Post your page on your WhatsApp, Facebook or Instagram. More shares bring more orders.</p>
        <div class="mt-3 space-y-3">
            @foreach ($businesses as $b)
                <div class="flex items-center gap-3 rounded-xl bg-slate-50 p-3">
                    @if ($b['image'])
                        <img src="{{ $b['image'] }}" alt="" class="h-16 w-16 shrink-0 rounded-xl object-cover">
                    @endif
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-slate-900">{{ $b['name'] }}</p>
                        <p class="text-xs text-slate-500">{{ $b['kind'] }}</p>
                    </div>
                    <button type="button" class="inline-flex min-h-11 shrink-0 items-center rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white"
                            data-share data-share-text="{{ $b['text'] }}" data-share-image="{{ $b['image'] }}" data-share-open="https://wa.me/?text={{ rawurlencode($b['text']) }}"
                            data-share-done="Shared ✓">Share</button>
                </div>
            @endforeach
        </div>
    </section>
@endif
