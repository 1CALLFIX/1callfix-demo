<div wire:poll.15s="refreshCount"
     x-data="{
        ring() {
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                [0, 0.35].forEach((at) => {
                    const osc = ctx.createOscillator(); const gain = ctx.createGain();
                    osc.frequency.value = 880; osc.connect(gain); gain.connect(ctx.destination);
                    gain.gain.setValueAtTime(0.25, ctx.currentTime + at);
                    gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + at + 0.3);
                    osc.start(ctx.currentTime + at); osc.stop(ctx.currentTime + at + 0.3);
                });
            } catch (e) {}
        }
     }"
     x-on:seller-new-order.window="ring()">
    @if ($waiting > 0 && ! request()->routeIs('seller.orders'))
        <a href="{{ route('seller.orders') }}" wire:navigate role="status"
           class="mb-4 flex items-center justify-between gap-3 rounded-2xl border-2 border-amber-400 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900">
            <span>{{ $waiting }} new {{ \Illuminate\Support\Str::plural('order', $waiting) }} waiting for you</span>
            <span class="rounded-lg bg-amber-600 px-3 py-1.5 text-white">Open orders →</span>
        </a>
    @endif
</div>
