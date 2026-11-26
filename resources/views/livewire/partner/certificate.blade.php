{{-- Owner 2026-11-26: My certificate (App\Livewire\Partner\CertificateScreen). Plain steps until the business is
     approved, then the certificate itself (the same design as the PDF: partner.certificate.certificate), the door
     sticker and one big print button. --}}
@php
    // The page has 1 rem padding each side: the preview fills a phone's width and stays 552 px wide on bigger screens.
    $certSize = 'min(8px, calc((100vw - 2rem) / 69))';
    $stickerSize = 'min(6px, calc((100vw - 4rem) / 33))';
    $waiting = [
        'draft' => ['bg-slate-100 text-slate-700', 'Not sent yet', 'Finish your hall and send it for review. Your certificate is made when 1CallFix approves it.'],
        'pending' => ['bg-amber-100 text-amber-800', 'Being checked', 'Your certificate is made when 1CallFix approves your business. Usually within a day.'],
        'rejected' => ['bg-rose-100 text-rose-800', 'Needs changes', 'Make the changes 1CallFix asked for and send it again. Your certificate comes after approval.'],
        'off' => ['bg-rose-100 text-rose-800', 'Switched off', 'Your business is switched off on 1CallFix right now. Call us to switch it on again.'],
    ];
@endphp
<div class="space-y-4">
    <div>
        <h1 class="text-2xl font-bold tracking-tight">My certificate</h1>
        <p class="text-sm text-slate-600">Your “Verified 1CallFix Partner” certificate. Print it and show it at your counter: customers trust a verified business.</p>
    </div>

    @if ($notice)
        <div role="status" class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ $notice }}</div>
    @endif

    @forelse ($items as $item)
        <section class="space-y-3" data-testid="certificate-{{ $item['id'] }}" data-state="{{ $item['state'] }}">
            @if ($items->count() > 1)
                <h2 class="text-lg font-bold">{{ $item['name'] }}</h2>
            @endif

            @if ($item['state'] !== 'ready')
                @php [$badge, $badgeText, $help] = $waiting[$item['state']]; @endphp
                <ol class="space-y-3">
                    <li class="flex gap-3 rounded-2xl border border-slate-200 bg-white p-4">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-blue-600 text-sm font-bold text-white">1</span>
                        <div class="min-w-0">
                            <p class="font-semibold">✅ Approved by 1CallFix</p>
                            <span class="mt-1 inline-block rounded-full px-2 py-0.5 text-xs font-semibold {{ $badge }}">{{ $badgeText }}</span>
                            <p class="mt-1 text-sm text-slate-600">{{ $help }}</p>
                        </div>
                    </li>
                    <li class="flex gap-3 rounded-2xl border border-slate-200 bg-white p-4">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-blue-600 text-sm font-bold text-white">2</span>
                        <div class="min-w-0">
                            <p class="font-semibold">🏅 Your certificate</p>
                            <p class="text-sm text-slate-600">Ready to print here, with a “Scan to order” sticker for your door.</p>
                        </div>
                    </li>
                </ol>
            @elseif ($item['status'] === 'expired')
                <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4">
                    <p class="font-semibold text-amber-900">Your certificate expired on {{ $item['c']['valid_until'] }}</p>
                    <p class="mt-1 text-sm text-amber-900">Its QR now says “Certificate expired”. Get this year's one and print it.</p>
                    <button type="button" wire:click="renew({{ $item['id'] }})" class="mt-3 inline-flex min-h-11 items-center rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white">Get this year's certificate</button>
                </div>
            @elseif ($item['status'] !== 'active')
                <div class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900">
                    Your account is not active on 1CallFix right now, so the certificate cannot be printed. Please call us.
                </div>
            @else
                @php $c = $item['c']; @endphp
                <div class="mx-auto w-fit overflow-hidden shadow" data-testid="certificate-preview">
                    @include('partner.certificate.certificate', ['c' => $c, 'fontSize' => $certSize])
                </div>

                <a href="{{ $item['printUrl'] }}" target="_blank" rel="noopener" data-testid="certificate-print"
                   class="flex min-h-12 w-full items-center justify-center gap-2 rounded-xl px-4 text-base font-bold text-white" style="background: {{ $c['colour'] }}">
                    <span aria-hidden="true">⬇️</span> Download to print
                </a>

                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                    <p class="font-semibold">How to use it</p>
                    <ol class="mt-2 space-y-2 text-sm text-slate-700">
                        <li class="flex gap-2"><span class="font-bold">1.</span> Print both pages in colour on A4 paper (any print shop can do it).</li>
                        <li class="flex gap-2"><span class="font-bold">2.</span> Frame page 1, the certificate, at your counter or on the wall.</li>
                        <li class="flex gap-2"><span class="font-bold">3.</span> Page 2 has two stickers: one for your door, one for your counter. Customers scan them to {{ \Illuminate\Support\Str::lower(\Illuminate\Support\Str::after($c['cta'], 'Scan to ')) }}.</li>
                    </ol>
                    <p class="mt-3 text-xs text-slate-500">ID {{ $c['code'] }} · valid till {{ $c['valid_until'] }}. Anyone can scan the certificate's QR to check it is genuine.</p>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                    <p class="mb-3 font-semibold">Page 2: your door sticker</p>
                    <div class="flex justify-center">
                        @include('partner.certificate.sticker', ['c' => $c, 'fontSize' => $stickerSize])
                    </div>
                </div>
            @endif
        </section>
    @empty
        <div class="rounded-2xl border border-slate-200 bg-white p-5 text-sm text-slate-700" data-testid="certificate-none">
            Certificates are for shops, restaurants, hotels, function halls and event organisers. Add your business first and send it for approval.
        </div>
    @endforelse
</div>
