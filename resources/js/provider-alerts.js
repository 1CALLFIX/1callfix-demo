/*
 | Provider foreground alerts (Phase PN1, calling-style ring upgrade).
 |
 | Scope, deliberately: this makes the provider web app loud while its tab is
 | OPEN. Closed-app / locked-phone delivery is FCM's job (push-notifications.js
 | + firebase-messaging-sw.js) and is untouched — the ring here is an
 | enhancement on top of it, never a replacement.
 |
 | Everything is driven by the provider Livewire components' existing
 | wire:poll. Two browser events (Livewire re-emits `$this->dispatch()` as a
 | CustomEvent on `window`, named params on `event.detail`):
 |
 |   provider-alert-offers   detail: { count, offers? }
 |       `count` is the number of live job offers (the contract). `offers` is
 |       display-only detail for the banner: [{ id, service, code, price,
 |       distance, when, area, expires_in }]. Every event REPLACES the whole
 |       set — the server's list is the only authority on whether an offer is
 |       live; this file never decides validity, it only counts the seconds
 |       the server said were left.
 |
 |   provider-alert-status   detail: { title, body }
 |       The held job changed status. One chime (+ OS notification if hidden).
 |
 | Sources of provider-alert-offers: Jobs\Index and Dashboard (their own polls)
 | and, on every other provider page, the layout-mounted OfferWatcher component
 | (1CF-FIX-ALERT-002). All apply the same server-side live-offer predicate.
 | With several provider tabs open each polls and shows the banner, but a Web
 | Lock (`ringLock` below) lets only one tab sound the ring.
 |
 | Delivery: this is a Vite entry loaded from the layout <head> (same as
 | push-notifications.js), so it is evaluated exactly once per document —
 | wire:navigate keeps head modules. It used to be a raw <script> in <body>,
 | which wire:navigate re-evaluated on every visit ("CHIME_INTERVAL_MS has
 | already been declared" + duplicate listeners/timers). The guard below is
 | belt-and-braces only.
 |
 | Lifecycle: page-lifetime singletons (AudioContext, priming listeners) live
 | at module level; everything tied to a page lives in the `providerOfferAlert`
 | Alpine component, which the layout body owns — so wire:navigate destroying
 | the body runs destroy(), which removes its listeners and stops the timers
 | and the ring.
 */

const GUARD = '__oneCallFixProviderAlerts';

/* ---- ring pattern: "tring-tring ...... tring-tring" ---- */
const RING_BURST_S = 0.42; // one "tring"
const RING_GAP_S = 0.18; // between the two trings
const RING_PAUSE_S = 1.5; // silence before the pair repeats
const RING_CYCLE_MS = Math.round((RING_BURST_S * 2 + RING_GAP_S + RING_PAUSE_S) * 1000);
const RING_CARRIERS_HZ = [1350, 1650]; // two bell partials
const RING_TREMOLO_HZ = 22; // the rattling hammer that makes it a bell, not a beep
const RING_PEAK = 0.9; // hot on purpose (old chime peaked ~0.5 on a sine); the bus compressor guards clipping

function setup() {
    /* ------------------------------ audio ------------------------------ */

    const audio = {
        ctx: null,
        bus: null,
        primed: false,
        unlocked: false,
        blocked: false,
        live: new Set(), // ring bursts currently scheduled/sounding

        /**
         * The single AudioContext. Not created until the browser would allow
         * it (a user gesture has happened), so a blocked page logs nothing and
         * the banner stays visual-only until the first tap.
         */
        ensure() {
            if (this.ctx === null) {
                const Ctor = window.AudioContext || window.webkitAudioContext;
                const activated = this.primed || navigator.userActivation?.hasBeenActive === true;
                if (!Ctor || !activated) return null;
                try {
                    this.ctx = new Ctor();
                } catch (e) {
                    return null;
                }
                this.bus = this.ctx.createDynamicsCompressor();
                this.bus.connect(this.ctx.destination);
            }
            if (this.ctx.state !== 'running') {
                this.ctx.resume().catch(() => {});
            }
            return this.ctx;
        },

        running() {
            return this.ctx !== null && this.ctx.state === 'running';
        },

        setBlocked(blocked) {
            if (this.blocked === blocked) return;
            this.blocked = blocked;
            window.dispatchEvent(new CustomEvent('provider-alert-audio', { detail: { blocked } }));
        },
    };

    /** Status-update chime — unchanged in character, kept separate from the offer ring. */
    function playChime() {
        const ctx = audio.ensure();
        if (!ctx || !audio.running()) return;

        const now = ctx.currentTime;
        const master = ctx.createGain();
        master.gain.value = 0.0001;
        master.connect(audio.bus);
        master.gain.exponentialRampToValueAtTime(0.5, now + 0.03);
        master.gain.exponentialRampToValueAtTime(0.0001, now + 0.9);

        [880, 1320].forEach((freq, i) => {
            const osc = ctx.createOscillator();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(freq, now + i * 0.12);
            osc.connect(master);
            osc.start(now + i * 0.12);
            osc.stop(now + 0.9);
            osc.onended = () => { if (i === 1) master.disconnect(); };
        });
    }

    /**
     * One "tring": two triangle partials run through a 22 Hz tremolo (the
     * rattling old-bell character) under a short attack/release envelope.
     * Scheduled on the audio clock, so the pair is tight regardless of
     * timer jitter.
     */
    function scheduleBurst(ctx, at) {
        const env = ctx.createGain();
        env.gain.setValueAtTime(0.0001, at);
        env.gain.linearRampToValueAtTime(RING_PEAK, at + 0.015);
        env.gain.setValueAtTime(RING_PEAK, at + RING_BURST_S - 0.03);
        env.gain.linearRampToValueAtTime(0.0001, at + RING_BURST_S);
        env.connect(audio.bus);

        // Tremolo: gain swings 0..1 (0.5 base + 0.5 * sine).
        const tremolo = ctx.createGain();
        tremolo.gain.value = 0.5;
        tremolo.connect(env);
        const lfo = ctx.createOscillator();
        lfo.frequency.value = RING_TREMOLO_HZ;
        const lfoDepth = ctx.createGain();
        lfoDepth.gain.value = 0.5;
        lfo.connect(lfoDepth);
        lfoDepth.connect(tremolo.gain);

        const oscs = [lfo];
        RING_CARRIERS_HZ.forEach((hz) => {
            const osc = ctx.createOscillator();
            osc.type = 'triangle';
            osc.frequency.value = hz;
            const level = ctx.createGain();
            level.gain.value = 0.5;
            osc.connect(level);
            level.connect(tremolo);
            oscs.push(osc);
        });

        const burst = { env, oscs };
        audio.live.add(burst);
        oscs[oscs.length - 1].onended = () => {
            audio.live.delete(burst);
            try { env.disconnect(); } catch (e) { /* already gone */ }
        };
        oscs.forEach((osc) => {
            osc.start(at);
            osc.stop(at + RING_BURST_S + 0.02);
        });
    }

    function scheduleRingCycle(ctx) {
        const t0 = ctx.currentTime + 0.05;
        scheduleBurst(ctx, t0);
        scheduleBurst(ctx, t0 + RING_BURST_S + RING_GAP_S);
    }

    /** Cut anything still sounding (fast fade, no click) — used on stop. */
    function silenceRing() {
        const ctx = audio.ctx;
        audio.live.forEach((burst) => {
            try {
                if (ctx) {
                    const t = ctx.currentTime;
                    burst.env.gain.cancelScheduledValues(t);
                    burst.env.gain.setTargetAtTime(0.0001, t, 0.008);
                    burst.oscs.forEach((osc) => { try { osc.stop(t + 0.05); } catch (e) { /* not started */ } });
                }
            } catch (e) { /* context closed */ }
        });
    }

    /* ---------------------- cross-tab ring lease ---------------------- */

    // Every open provider tab now polls offers (OfferWatcher) and would ring.
    // One lock, held for as long as a tab is ringing, lets exactly one of them
    // make the noise; the others still show the banner. Web Locks is atomic
    // and the browser releases it if the owning tab closes or crashes, so
    // there is no lease to expire or renew. `ifAvailable` never queues — a
    // tab that loses simply retries on its next ring cycle. Without the API
    // (older browsers) every tab rings, exactly as before.
    const RING_LOCK = 'onecallfix-provider-ring';

    const ringLock = {
        held: false,
        requesting: false,
        release: null,

        /** May this tab sound the ring right now? A grant arrives async and restarts the ring. */
        acquire() {
            if (!navigator.locks || typeof navigator.locks.request !== 'function') return true;
            if (this.held) return true;
            if (this.requesting) return false;
            this.requesting = true;
            navigator.locks.request(RING_LOCK, { ifAvailable: true }, (lock) => {
                this.requesting = false;
                if (!lock) return undefined; // another tab is ringing
                if (!ringer.active) return undefined; // offer cleared while asking
                this.held = true;
                ringer.restart(); // sound now rather than a whole cycle later
                return new Promise((resolve) => {
                    this.release = () => {
                        this.held = false;
                        this.release = null;
                        resolve();
                    };
                });
            }).catch(() => { this.requesting = false; });
            return false;
        },

        drop() {
            if (this.release) this.release();
        },
    };

    /* ------------------------------ ringer ------------------------------ */

    /** Idempotent repeating ring: at most one timer, ever. */
    const ringer = {
        active: false,
        timer: null,
        nextAt: 0, // audio-clock time before which no new cycle may be scheduled

        start() {
            if (this.active) return;
            this.active = true;
            this.cycle();
        },

        cycle() {
            this.timer = null;
            if (!this.active) return;

            const ctx = audio.ensure();
            if (ctx && audio.running()) {
                audio.setBlocked(false);
                // Only a tab that can actually make sound competes for the
                // ring lock, so a tab with blocked audio never mutes one that
                // could. Losing the lock is silent: another tab is ringing.
                // However a cycle is triggered (timer, or restart() after the
                // first-gesture unlock), never schedule two within one
                // cycle's span — that would double the pair.
                if (ringLock.acquire() && ctx.currentTime >= this.nextAt) {
                    scheduleRingCycle(ctx);
                    this.nextAt = ctx.currentTime + RING_CYCLE_MS / 1000 - 0.25;
                }
            } else {
                // Autoplay policy (or a suspended context): keep the visual
                // alert, flag it, and retry each cycle / on the next gesture.
                audio.unlocked = false; // let the hint button / next gesture unlock again
                audio.setBlocked(true);
            }
            // Enforce "at most one timer" here rather than trusting every
            // caller: a cycle can be re-entered (restart() from the lock grant)
            // before this one has scheduled its successor.
            if (this.timer !== null) window.clearTimeout(this.timer);
            this.timer = window.setTimeout(() => this.cycle(), RING_CYCLE_MS);
        },

        /** Re-run the cycle now (audio just became available mid-offer). */
        restart() {
            if (!this.active) return;
            if (this.timer !== null) window.clearTimeout(this.timer);
            this.cycle();
        },

        stop() {
            this.active = false;
            this.nextAt = 0;
            if (this.timer !== null) {
                window.clearTimeout(this.timer);
                this.timer = null;
            }
            silenceRing();
            ringLock.drop();
        },
    };

    /* ------------------------ first-gesture priming ------------------------ */

    // The AudioContext and Notification permission both need a user gesture.
    // Prime on the first real interaction anywhere; keep listening until the
    // context is actually running (some browsers need a second gesture).
    const PRIME_EVENTS = ['pointerdown', 'pointerup', 'touchend', 'keydown', 'click'];
    let notificationAsked = false;

    function detachPrime() {
        PRIME_EVENTS.forEach((name) => window.removeEventListener(name, prime, true));
    }

    function prime(event) {
        // Only a real user gesture may unlock audio; a scripted .click() is
        // not one and the browser would just log an autoplay warning.
        if (event && event.isTrusted === false) return;
        audio.primed = true;
        // Notification permission needs a real gesture — never ask from the
        // load-time prime below (no event).
        if (event && !notificationAsked && 'Notification' in window && Notification.permission === 'default') {
            notificationAsked = true;
            try { Notification.requestPermission().catch(() => {}); } catch (e) { /* older Safari */ }
        }
        const ctx = audio.ensure();
        if (!ctx) return;
        ctx.resume().then(() => {
            if (!audio.running() || audio.unlocked) return;
            audio.unlocked = true; // one tap fires several gesture events; unlock once
            detachPrime();
            audio.setBlocked(false);
            ringer.restart();
        }).catch(() => {});
    }

    PRIME_EVENTS.forEach((name) => window.addEventListener(name, prime, { capture: true, passive: true }));
    // Already activated in this document (e.g. after a wire:navigate the
    // gesture is remembered): prime without waiting for another tap.
    if (navigator.userActivation?.hasBeenActive === true) prime();

    /* ------------------------- OS notification ------------------------- */

    let osNotification = null;

    function showOsNotification(title, body, tag) {
        if (!('Notification' in window) || Notification.permission !== 'granted') return null;
        try {
            return new Notification(title, { body, tag, renotify: true });
        } catch (e) {
            return null;
        }
    }

    function closeOsNotification() {
        if (osNotification === null) return;
        try { osNotification.close(); } catch (e) { /* ignore */ }
        osNotification = null;
    }

    /* --------------------------- Alpine component --------------------------- */

    /* ------------------------ location heartbeat ---------------------------- */

    // While a provider is online, the browser re-sends its location every two
    // minutes through the component's own `goOnline(lat, lng)` (the dispatch
    // freshness gate reads the resulting location_updated_at). The cadence, the
    // visibility/geolocation guards and the call itself are unchanged from the
    // inline x-init this replaced; what changed is ownership.
    //
    // That inline `x-init="setInterval(...)"` was never cleared. Every render of
    // the marker (header chip, drawer copy, Dashboard card), every wire:navigate
    // and — worst — going offline left its interval running, and it kept calling
    // `$wire.goOnline`, quietly flipping the provider back online. Here each
    // marker element is a `providerHeartbeat` Alpine component that only JOINS
    // and LEAVES a page-wide set of members; ONE interval exists while the set
    // is non-empty and is cleared the moment it empties. Alpine runs destroy()
    // when the marker leaves the DOM (offline re-render, wire:navigate, morph),
    // so the lifecycle is the element's, not a hand-rolled one.
    const HEARTBEAT_MS = 120000;
    const heartbeatMembers = new Set();
    let heartbeatTimer = null;

    /** Report a fix through the oldest live marker — one request, whatever the copies. */
    function heartbeatReport(lat, lng) {
        const owner = heartbeatMembers.values().next().value;
        // An empty set means the provider went offline (or left) while the
        // fix was resolving: report nothing rather than resurrect the session.
        if (owner) owner.send(lat, lng);
    }

    function heartbeatTick() {
        if (heartbeatMembers.size === 0 || document.hidden || !navigator.geolocation) return;
        navigator.geolocation.getCurrentPosition(
            (p) => heartbeatReport(p.coords.latitude, p.coords.longitude),
            () => {},
            { timeout: 8000 },
        );
    }

    function heartbeatJoin(member) {
        heartbeatMembers.add(member);
        if (heartbeatTimer === null) {
            heartbeatTimer = window.setInterval(heartbeatTick, HEARTBEAT_MS);
        }
    }

    function heartbeatLeave(member) {
        heartbeatMembers.delete(member);
        if (heartbeatMembers.size === 0 && heartbeatTimer !== null) {
            window.clearInterval(heartbeatTimer);
            heartbeatTimer = null;
        }
    }

    /* --------------------------- Alpine components -------------------------- */

    let registered = false;

    function registerAlpine() {
        if (registered || !window.Alpine || typeof window.Alpine.data !== 'function') return;
        registered = true;

        // Marker for "this provider is online — keep the location fresh". Put on
        // an element rendered ONLY while online, so leaving that state removes
        // the element and Alpine's destroy() stops the heartbeat.
        window.Alpine.data('providerHeartbeat', () => {
            // One stable identity per instance: join/leave are idempotent even
            // if Alpine re-runs init() on a morphed element.
            const member = { send: null };
            return {
                init() {
                    member.send = (lat, lng) => this.$wire.goOnline(lat, lng);
                    heartbeatJoin(member);
                },
                destroy() {
                    member.send = null;
                    heartbeatLeave(member);
                },
            };
        });

        // The per-offer "Ns left" pill on the Job Offers list. Display only:
        // the server re-renders the list every poll and drops expired offers.
        //
        // The server's seconds-left arrive as `data-seconds`, deliberately NOT
        // as an x-data argument. wire:poll morphs the pill on every render, and
        // an x-data attribute whose value changes each time makes Alpine tear
        // the component down and rebuild it in place while the pill's x-text
        // stays bound to the OLD scope — the text then froze between renders.
        // With a constant x-data the component (and its one interval) lives
        // until the element really goes away; a re-render only rewrites
        // data-seconds, which the narrow attribute observer folds back into `n`.
        window.Alpine.data('providerOfferCountdown', () => {
            let timer = null;
            let observer = null;
            const stopTimer = () => {
                if (timer !== null) {
                    window.clearInterval(timer);
                    timer = null;
                }
            };
            const stop = () => {
                stopTimer();
                if (observer !== null) {
                    observer.disconnect();
                    observer = null;
                }
            };

            return {
                n: 0,
                init() {
                    const el = this.$el;
                    // Idempotent: at most one interval per pill, however many
                    // times the server re-syncs it.
                    const run = () => {
                        if (timer !== null || this.n <= 0) return;
                        timer = window.setInterval(() => {
                            if (this.n > 0) this.n--;
                            else stopTimer();
                        }, 1000);
                    };
                    // The server's figure replaces the local count (same rule
                    // as the banner: the server list is the authority).
                    const sync = () => {
                        this.n = Math.max(0, Number(el.dataset.seconds) || 0);
                        run();
                    };

                    sync();
                    if (typeof MutationObserver === 'function') {
                        observer = new MutationObserver(sync);
                        observer.observe(el, { attributes: true, attributeFilter: ['data-seconds'] });
                    }
                },
                destroy: stop,
            };
        });

        window.Alpine.data('providerOfferAlert', () => {
            // Handler references live in the closure, not on the reactive
            // object, so destroy() removes exactly what init() added.
            let onOffers = null;
            let onStatus = null;
            let onVisibility = null;
            let onAudio = null;
            let onPageHide = null;
            let ticker = null;

            return {
                offers: [], // display summaries, each with a local `deadline` (ms epoch)
                count: 0, // the contract count from the last event
                now: Date.now(),
                audioBlocked: false,
                respondUrl: '',
                onOffersPage: false,

                init() {
                    this.respondUrl = this.$el.dataset.respondUrl || '';
                    this.onOffersPage = this.$el.dataset.onOffersPage === '1';
                    this.audioBlocked = audio.blocked;

                    onOffers = (event) => this.applyOffers(event?.detail);
                    onStatus = (event) => {
                        const title = event?.detail?.title || 'Job update';
                        const body = event?.detail?.body || '';
                        playChime();
                        if (document.hidden) showOsNotification(title, body, 'provider-status');
                    };
                    // Tab back in view: the on-screen banner now does the job.
                    onVisibility = () => { if (!document.hidden) closeOsNotification(); };
                    onAudio = (event) => { this.audioBlocked = Boolean(event?.detail?.blocked); };
                    onPageHide = () => this.teardown();

                    window.addEventListener('provider-alert-offers', onOffers);
                    window.addEventListener('provider-alert-status', onStatus);
                    window.addEventListener('provider-alert-audio', onAudio);
                    window.addEventListener('pagehide', onPageHide);
                    document.addEventListener('visibilitychange', onVisibility);
                },

                destroy() {
                    if (onOffers) window.removeEventListener('provider-alert-offers', onOffers);
                    if (onStatus) window.removeEventListener('provider-alert-status', onStatus);
                    if (onAudio) window.removeEventListener('provider-alert-audio', onAudio);
                    if (onPageHide) window.removeEventListener('pagehide', onPageHide);
                    if (onVisibility) document.removeEventListener('visibilitychange', onVisibility);
                    onOffers = onStatus = onAudio = onPageHide = onVisibility = null;
                    this.teardown();
                },

                /** Stop everything this component started. Safe to call repeatedly. */
                teardown() {
                    if (ticker !== null) {
                        window.clearInterval(ticker);
                        ticker = null;
                    }
                    ringer.stop();
                    closeOsNotification();
                    this.offers = [];
                    this.count = 0;
                },

                /** A fresh, server-authoritative offer set. Replaces — never merges. */
                applyOffers(detail) {
                    const list = Array.isArray(detail?.offers) ? detail.offers : [];
                    const at = Date.now();
                    this.now = at;
                    this.count = Number(detail?.count ?? list.length) || 0;
                    this.offers = list.map((o) => ({
                        id: o.id,
                        service: o.service || 'Service',
                        code: o.code || '',
                        price: o.price || '',
                        distance: o.distance || '',
                        when: o.when || '',
                        area: o.area || '',
                        deadline: at + Math.max(0, Number(o.expires_in) || 0) * 1000,
                    }));
                    this.sync();
                },

                /** Offers whose server-given seconds have not yet run out. */
                get live() {
                    return this.offers.filter((o) => o.deadline > this.now);
                },

                /**
                 * Whether an offer is standing. With per-offer detail, expiry is
                 * local (the countdown reached zero). With a bare `count` (the
                 * original contract, no detail) the count alone decides.
                 */
                get active() {
                    if (this.count <= 0) return false;
                    return this.offers.length === 0 ? true : this.live.length > 0;
                },

                get primary() {
                    return this.live[0] || null;
                },

                get extra() {
                    return Math.max(0, (this.offers.length ? this.live.length : this.count) - 1);
                },

                get remaining() {
                    return this.primary ? Math.max(0, Math.ceil((this.primary.deadline - this.now) / 1000)) : 0;
                },

                get meta() {
                    const p = this.primary;
                    if (!p) return '';
                    return [p.code, p.distance, p.when, p.area].filter(Boolean).join(' · ');
                },

                /** Reconcile ring / ticker / OS notification with the current set. */
                sync() {
                    if (this.active) {
                        ringer.start();
                        if (ticker === null) {
                            ticker = window.setInterval(() => {
                                this.now = Date.now();
                                this.sync();
                            }, 1000);
                        }
                        if (document.hidden && osNotification === null) {
                            const n = this.offers.length ? this.live.length : this.count;
                            osNotification = showOsNotification(
                                n === 1 ? 'New job offer' : `${n} new job offers`,
                                'Open the partner app to accept.',
                                'provider-offer',
                            );
                        }
                    } else {
                        // Offer count zero / accepted / declined / expired /
                        // gone from the server list.
                        if (ticker !== null) {
                            window.clearInterval(ticker);
                            ticker = null;
                        }
                        ringer.stop();
                        closeOsNotification();
                    }
                },

                /** The banner's own "tap to enable sound" affordance. */
                enableSound(event) {
                    prime(event);
                },
            };
        });
    }

    // alpine:init covers Alpine not having started yet (the normal case: this
    // module runs before DOMContentLoaded, when Livewire starts Alpine). The
    // direct call covers Alpine already being present. `registered` makes the
    // pair idempotent.
    document.addEventListener('alpine:init', registerAlpine, { once: true });
    registerAlpine();
}

if (!window[GUARD]) {
    window[GUARD] = true;
    setup();
}
