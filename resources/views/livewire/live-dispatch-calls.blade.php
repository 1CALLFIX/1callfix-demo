{{-- Owner request 2026-10-20 — live calls bar (see App\Livewire\LiveDispatchCalls). Countdowns tick in the browser
     every second; the server list refreshes every 15 s. --}}
<div wire:poll.15s id="live-dispatch-calls">
    @if ($calls->isNotEmpty())
        <div class="bg-amber-50 border-b border-amber-200 px-4 py-2 text-sm" role="region" aria-label="Live calls">
            <div class="flex items-center gap-3 mb-1">
                <span class="font-semibold text-amber-900">Live calls — {{ $calls->count() }} booking{{ $calls->count() === 1 ? '' : 's' }} waiting for a provider</span>
                <button type="button" data-live-calls-sound class="ml-auto text-xs rounded border border-amber-300 bg-white px-2 py-0.5 text-amber-900 hover:bg-amber-100">Sound off</button>
            </div>
            <div class="flex flex-wrap gap-2">
                @foreach ($calls as $call)
                    <a href="{{ route('admin.bookings.show', $call['id']) }}" wire:key="live-call-{{ $call['id'] }}"
                       data-live-call
                       data-broadcast-ends-at="{{ $call['broadcast_ends_at'] }}"
                       data-cancels-at="{{ $call['cancels_at'] }}"
                       data-warn-at="{{ $call['warn_at'] }}"
                       class="rounded-lg border border-amber-300 bg-amber-100 px-3 py-1.5 text-xs text-amber-950 hover:shadow">
                        <span class="font-mono font-semibold">{{ $call['code'] }}</span>
                        @if ($call['service']) · {{ $call['service'] }} @endif
                        · <span data-stage>Calling providers</span>
                        · auto-cancel in <span data-countdown class="font-mono font-semibold">—</span>
                        <span class="opacity-75">({{ $call['cancels_at_text'] }})</span>
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    <script>
        (function () {
            if (window.__ocfLiveCalls) return;
            window.__ocfLiveCalls = true;

            var KEY = 'ocf.liveCalls.sound';
            var ctx = null;
            var lastBeep = 0;

            function soundOn() {
                try { return localStorage.getItem(KEY) === '1'; } catch (e) { return false; }
            }

            function audio() {
                try {
                    ctx = ctx || new (window.AudioContext || window.webkitAudioContext)();
                    if (ctx.state === 'suspended') ctx.resume();
                } catch (e) { ctx = null; }
                return ctx;
            }

            function beep() {
                var a = audio();
                if (!a) return;
                [0, 0.3, 0.6].forEach(function (t) {
                    var o = a.createOscillator();
                    var g = a.createGain();
                    o.type = 'square';
                    o.frequency.value = 880;
                    g.gain.value = 0.08;
                    o.connect(g);
                    g.connect(a.destination);
                    o.start(a.currentTime + t);
                    o.stop(a.currentTime + t + 0.18);
                });
            }

            function fmt(ms) {
                var s = Math.max(0, Math.floor(ms / 1000));
                return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
            }

            function tick() {
                var now = Date.now();
                var urgent = false;
                document.querySelectorAll('[data-live-call]').forEach(function (el) {
                    var cancelsAt = +el.dataset.cancelsAt;
                    var warnAt = +el.dataset.warnAt;
                    var broadcastEndsAt = +el.dataset.broadcastEndsAt;
                    var countdown = el.querySelector('[data-countdown]');
                    if (countdown) countdown.textContent = now >= cancelsAt ? 'now' : fmt(cancelsAt - now);
                    var stage = el.querySelector('[data-stage]');
                    if (stage) stage.textContent = now < broadcastEndsAt
                        ? 'Calling providers (' + fmt(broadcastEndsAt - now) + ' left)'
                        : 'No one accepted — assign now';
                    var hot = now >= warnAt;
                    el.classList.toggle('bg-red-600', hot);
                    el.classList.toggle('text-white', hot);
                    el.classList.toggle('border-red-700', hot);
                    el.classList.toggle('animate-pulse', hot);
                    el.classList.toggle('bg-amber-100', !hot);
                    el.classList.toggle('text-amber-950', !hot);
                    if (hot) urgent = true;
                });
                var button = document.querySelector('[data-live-calls-sound]');
                if (button) button.textContent = soundOn() ? 'Sound on 🔔' : 'Sound off 🔕';
                if (urgent && soundOn() && now - lastBeep > 15000) {
                    lastBeep = now;
                    beep();
                }
            }

            document.addEventListener('click', function (e) {
                // Browsers only allow sound after a click on the page; any click unlocks it.
                if (soundOn()) audio();
                if (!e.target.closest('[data-live-calls-sound]')) return;
                try { localStorage.setItem(KEY, soundOn() ? '0' : '1'); } catch (err) {}
                if (soundOn()) beep();
                tick();
            });

            setInterval(tick, 1000);
            tick();
        })();
    </script>
</div>
