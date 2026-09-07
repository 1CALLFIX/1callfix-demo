/**
 * Provider foreground alerts (Phase PN1).
 *
 * Scope, deliberately: this only makes the provider dashboard louder while
 * its tab is OPEN. It is not background push — there is no service worker,
 * no push subscription, no FCM here (see the phased proposal). Everything
 * below is driven by the components' existing `wire:poll`, so it needs no
 * broadcaster and no queue worker.
 *
 * Two browser events, dispatched by the provider Livewire components on
 * every poll:
 *
 *   provider-alert-offers   detail: { count }   — how many live job offers
 *                           the provider currently has. > 0 starts a
 *                           repeating chime (and, if the tab is hidden, an
 *                           OS notification); back to 0 stops it.
 *
 *   provider-alert-status   detail: { title, body }  — the provider's held
 *                           job just changed status (started / on hold /
 *                           resumed / cancelled / en route). One chime, plus
 *                           an OS notification when the tab is hidden.
 *
 * Livewire v3 re-emits `$this->dispatch(...)` as a native CustomEvent on
 * `window`, with the named params on `event.detail` — that is all this file
 * listens to.
 */

const CHIME_INTERVAL_MS = 3000;

let audioCtx = null;
let offerLoopTimer = null;
let offerNotification = null;

/** Lazily create (and resume) a single AudioContext. Must be kicked off a user gesture the first time. */
function getAudioContext() {
    if (audioCtx === null) {
        const Ctor = window.AudioContext || window.webkitAudioContext;
        if (!Ctor) return null;
        audioCtx = new Ctor();
    }
    if (audioCtx.state === 'suspended') {
        audioCtx.resume().catch(() => {});
    }
    return audioCtx;
}

/**
 * A short two-tone chime, synthesised — no audio asset to ship or cache.
 * Two stacked sine partials with a quick attack/decay so it reads as an
 * alert, not a click.
 */
function playChime() {
    const ctx = getAudioContext();
    if (!ctx) return;

    const now = ctx.currentTime;
    const master = ctx.createGain();
    master.gain.value = 0.0001;
    master.connect(ctx.destination);

    // Rise then fall — peak ~0.5 so it is clearly audible without clipping.
    master.gain.exponentialRampToValueAtTime(0.5, now + 0.03);
    master.gain.exponentialRampToValueAtTime(0.0001, now + 0.9);

    [880, 1320].forEach((freq, i) => {
        const osc = ctx.createOscillator();
        osc.type = 'sine';
        osc.frequency.setValueAtTime(freq, now + i * 0.12);
        osc.connect(master);
        osc.start(now + i * 0.12);
        osc.stop(now + 0.9);
    });
}

function notificationsAllowed() {
    return 'Notification' in window && Notification.permission === 'granted';
}

function requestNotificationPermission() {
    if (!('Notification' in window)) return;
    if (Notification.permission === 'default') {
        Notification.requestPermission().catch(() => {});
    }
}

function showOsNotification(title, body, tag) {
    if (!notificationsAllowed()) return null;
    try {
        return new Notification(title, { body, tag, renotify: true });
    } catch (e) {
        return null;
    }
}

/* ----------------------------- job offers ----------------------------- */

function startOfferAlarm(count) {
    // Chime immediately, then keep chiming until the offer set clears —
    // "loud and repeating" is independent of the 4s poll cadence.
    if (offerLoopTimer === null) {
        playChime();
        offerLoopTimer = window.setInterval(playChime, CHIME_INTERVAL_MS);
    }

    if (document.hidden && offerNotification === null) {
        const label = count === 1 ? 'New job offer' : `${count} new job offers`;
        offerNotification = showOsNotification(label, 'Open the partner app to accept.', 'provider-offer');
    }
}

function stopOfferAlarm() {
    if (offerLoopTimer !== null) {
        window.clearInterval(offerLoopTimer);
        offerLoopTimer = null;
    }
    if (offerNotification !== null) {
        try { offerNotification.close(); } catch (e) { /* ignore */ }
        offerNotification = null;
    }
}

/* ------------------------------- wiring ------------------------------- */

function onOffers(event) {
    const count = Number(event?.detail?.count ?? 0) || 0;
    if (count > 0) {
        startOfferAlarm(count);
    } else {
        stopOfferAlarm();
    }
}

function onStatus(event) {
    const title = event?.detail?.title || 'Job update';
    const body = event?.detail?.body || '';
    playChime();
    if (document.hidden) {
        showOsNotification(title, body, 'provider-status');
    }
}

// A hidden tab that becomes visible again: drop the OS notification, the
// on-screen list is now doing the job. The chime loop keeps going while an
// offer still stands.
function onVisibility() {
    if (!document.hidden && offerNotification !== null) {
        try { offerNotification.close(); } catch (e) { /* ignore */ }
        offerNotification = null;
    }
}

function init() {
    window.addEventListener('provider-alert-offers', onOffers);
    window.addEventListener('provider-alert-status', onStatus);
    document.addEventListener('visibilitychange', onVisibility);

    // Notification permission and the AudioContext both need a user gesture
    // the first time. Ask on the first interaction anywhere on the page,
    // once.
    const primeOnce = () => {
        requestNotificationPermission();
        getAudioContext();
        window.removeEventListener('pointerdown', primeOnce);
        window.removeEventListener('keydown', primeOnce);
    };
    window.addEventListener('pointerdown', primeOnce, { once: false });
    window.addEventListener('keydown', primeOnce, { once: false });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
