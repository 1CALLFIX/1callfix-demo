<div class="space-y-4" x-data="{
        supported: ('BarcodeDetector' in window) && !! (navigator.mediaDevices && navigator.mediaDevices.getUserMedia),
        running: false, error: '', stream: null, last: '',
        async start() {
            this.error = '';
            try {
                this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
                this.$refs.video.srcObject = this.stream;
                await this.$refs.video.play();
                this.running = true;
                const detector = new BarcodeDetector({ formats: ['qr_code'] });
                const tick = async () => {
                    if (! this.running) return;
                    try {
                        const codes = await detector.detect(this.$refs.video);
                        if (codes.length && codes[0].rawValue !== this.last) {
                            this.last = codes[0].rawValue;
                            await $wire.scanned(this.last);
                            setTimeout(() => { this.last = ''; }, 2500);
                        }
                    } catch (e) {}
                    setTimeout(tick, 300);
                };
                tick();
            } catch (e) {
                this.error = 'Camera not available. Allow the camera for this site, or type the code.';
                this.stop();
            }
        },
        stop() { this.running = false; if (this.stream) { this.stream.getTracks().forEach((t) => t.stop()); } this.stream = null; },
        destroy() { this.stop(); },
    }">
    <a href="{{ route('organiser.events.edit', $event->id) }}" wire:navigate class="text-sm text-slate-600 hover:text-slate-900">&larr; {{ $event->title }}</a>
    <div class="flex items-center justify-between gap-3">
        <h1 class="text-2xl font-bold tracking-tight">Gate</h1>
        <p class="text-sm font-semibold text-slate-700" data-testid="gate-count">{{ $sales['checked_in'] }} / {{ $sales['people'] }} in</p>
    </div>

    @if ($result)
        <div role="status" data-testid="gate-result" @class(['rounded-2xl p-5 text-center text-lg font-bold',
            'bg-emerald-600 text-white' => $result['ok'], 'bg-red-600 text-white' => ! $result['ok']])>
            {{ $result['ok'] ? '✓' : '✗' }} {{ $result['message'] }}
        </div>
    @endif

    <div class="space-y-3 rounded-2xl border border-slate-200 bg-white p-4">
        <button type="button" x-show="supported && ! running" x-on:click="start()" class="flex min-h-12 w-full items-center justify-center rounded-xl bg-blue-600 px-4 font-semibold text-white hover:bg-blue-700">Scan with camera</button>
        <div x-show="running" x-cloak class="space-y-2">
            <video x-ref="video" playsinline muted class="aspect-square w-full rounded-xl bg-black object-cover"></video>
            <button type="button" x-on:click="stop()" class="min-h-11 w-full rounded-xl border border-slate-300 text-sm font-medium">Stop camera</button>
        </div>
        <p x-show="! supported" class="text-sm text-slate-600">This phone's browser cannot scan here. Type the code printed under the QR instead (Chrome on Android can scan).</p>
        <p x-show="error" x-text="error" x-cloak class="text-sm text-red-700"></p>

        <form wire:submit="check" class="flex gap-2">
            <label for="gate-code" class="sr-only">Ticket code</label>
            <input id="gate-code" type="text" wire:model="code" autocomplete="off" autocapitalize="characters" placeholder="Ticket code, e.g. T8K2..." class="min-h-12 flex-1 rounded-xl border border-slate-300 px-3 text-base uppercase tracking-widest">
            <button type="submit" class="min-h-12 rounded-xl bg-slate-900 px-4 font-semibold text-white">Check</button>
        </form>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-4 text-sm">
        @foreach ($sales['types'] as $t)
            <div class="flex justify-between border-t py-1.5 first:border-t-0"><span>{{ $t['name'] }}</span><span>{{ $t['sold'] }} sold of {{ $t['of'] }}</span></div>
        @endforeach
    </div>
</div>

