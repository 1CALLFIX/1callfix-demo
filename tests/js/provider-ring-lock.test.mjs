/*
 | Cross-tab behaviour of the provider offer ring (resources/js/provider-alerts.js,
 | Alpine.data 'providerOfferAlert' + the Web Locks ring lease).
 |
 | Run:  node --test tests/js/provider-ring-lock.test.mjs
 | (ProviderAlertRoutingJsTest runs this file under `php artisan test`.)
 |
 | Since OfferWatcher (1CF-FIX-ALERT-002) every open provider tab polls offers,
 | so every open tab would ring. One Web Lock, held while a tab rings, makes
 | exactly one tab sound the ring; the rest still show the banner. The real
 | module source is loaded once per simulated tab; only the browser edges are
 | fakes: a manual clock, an AudioContext that counts the bursts it is asked to
 | play, and ONE navigator.locks shared by every tab (that is what makes it a
 | cross-tab lock).
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const SRC = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../resources/js/provider-alerts.js');
const CYCLE_MS = Math.round((0.42 * 2 + 0.18 + 1.5) * 1000);
const flush = () => new Promise((resolve) => setImmediate(resolve));

function makeClock() {
    let now = 0;
    let nextId = 1;
    const timers = new Map();
    const add = (fn, ms, repeat) => {
        const id = nextId++;
        timers.set(id, { fn, ms, repeat, due: now + ms });
        return id;
    };

    return {
        setInterval: (fn, ms) => add(fn, ms, true),
        setTimeout: (fn, ms) => add(fn, ms, false),
        clearInterval: (id) => { timers.delete(id); },
        clearTimeout: (id) => { timers.delete(id); },
        get now() { return now; },
        get active() { return timers.size; },
        async advance(ms) {
            const end = now + ms;
            for (;;) {
                let next = null;
                for (const [id, t] of timers) {
                    if (t.due <= end && (next === null || t.due < next.t.due)) next = { id, t };
                }
                if (next === null) break;
                now = next.t.due;
                if (next.t.repeat) next.t.due += next.t.ms;
                else timers.delete(next.id);
                next.t.fn();
                await flush(); // let lock grants / resume() settle between timer callbacks
            }
            now = end;
        },
    };
}

/**
 * navigator.locks shared by every tab: ifAvailable only, held until the
 * callback's promise settles. Like the real API the callback runs
 * ASYNCHRONOUSLY; `sync: true` runs it inline instead, to prove the ringer
 * survives being re-entered from the grant.
 */
function makeLocks({ sync = false } = {}) {
    const held = new Set();
    const run = (fn) => (sync ? Promise.resolve(fn()) : Promise.resolve().then(fn));

    return {
        get heldCount() { return held.size; },
        request(name, options, callback) {
            if (options?.ifAvailable && held.has(name)) return run(() => callback(null));
            held.add(name);

            return run(() => callback({ name })).finally(() => held.delete(name));
        },
    };
}

/** One browser tab = one sandbox running the real module. */
function openTab(locks, { audio = 'running' } = {}) {
    const clock = makeClock();
    const registry = {};
    const listeners = new Map(); // type -> Set
    const stats = { bursts: 0, added: 0, removed: 0 };

    class FakeAudioContext {
        constructor() { this.state = audio; this.destination = {}; }
        get currentTime() { return clock.now / 1000; }
        resume() { if (audio === 'running') this.state = 'running'; return Promise.resolve(); }
        createDynamicsCompressor() { return { connect() {} }; }
        createGain() {
            const param = { value: 0, setValueAtTime() {}, linearRampToValueAtTime() {}, exponentialRampToValueAtTime() {}, cancelScheduledValues() {}, setTargetAtTime() {} };
            return { gain: param, connect() {}, disconnect() {} };
        }
        createOscillator() {
            const osc = { type: 'sine', frequency: { value: 0, setValueAtTime() {} }, connect() {}, stop() {}, start() { if (osc.type === 'triangle') stats.triangleStarts = (stats.triangleStarts || 0) + 1; } };
            return osc;
        }
    }

    const win = {
        AudioContext: FakeAudioContext,
        addEventListener(type, fn) { stats.added += 1; (listeners.get(type) || listeners.set(type, new Set()).get(type)).add(fn); },
        removeEventListener(type, fn) { stats.removed += 1; listeners.get(type)?.delete(fn); },
        dispatchEvent(event) { [...(listeners.get(event.type) || [])].forEach((fn) => fn(event)); },
        Alpine: { data(name, factory) { registry[name] = factory; } },
        setInterval: clock.setInterval,
        clearInterval: clock.clearInterval,
        setTimeout: clock.setTimeout,
        clearTimeout: clock.clearTimeout,
    };
    const nav = { userActivation: { hasBeenActive: true } };
    if (locks) nav.locks = locks;

    const sandbox = {
        window: win,
        document: { hidden: false, addEventListener() {}, removeEventListener() {} },
        navigator: nav,
        CustomEvent: class { constructor(type, init) { this.type = type; this.detail = init?.detail; } },
        MutationObserver: class { observe() {} disconnect() {} },
        console,
    };
    vm.runInContext(readFileSync(SRC, 'utf8'), vm.createContext(sandbox), { filename: 'provider-alerts.js' });
    assert.equal(typeof registry.providerOfferAlert, 'function');

    /** Mount the banner the way Alpine does: factory() with no args, `$el` on `this`, then init(). */
    const banner = () => {
        const data = registry.providerOfferAlert();
        data.$el = { dataset: { respondUrl: '/provider/jobs', onOffersPage: '0' } };
        data.init();

        return data;
    };
    const offers = (count) => win.dispatchEvent({
        type: 'provider-alert-offers',
        detail: {
            count,
            offers: Array.from({ length: count }, (_, i) => ({ id: i + 1, service: 'AC repair', code: `B${i}`, price: '₹500', expires_in: 3600 })),
        },
    });

    /** Live listeners for one window event type. */
    const listenerCount = (type) => listeners.get(type)?.size ?? 0;

    return { clock, win, stats, banner, offers, listenerCount, get bursts() { return (stats.triangleStarts || 0) / 4; } };
}

test('a lone tab rings as soon as the lock is granted', async () => {
    const locks = makeLocks();
    const tab = openTab(locks);
    const banner = tab.banner();

    tab.offers(1);
    await flush();

    assert.equal(banner.active, true);
    assert.ok(tab.bursts >= 1, 'the ring sounded without waiting a whole cycle');
    assert.equal(locks.heldCount, 1);
});

test('two tabs, one offer: only one rings, both show the banner', async () => {
    const locks = makeLocks();
    const a = openTab(locks);
    const b = openTab(locks);
    const bannerA = a.banner();
    const bannerB = b.banner();

    a.offers(1);
    await flush();
    b.offers(1);
    await flush();

    await Promise.all([a.clock.advance(CYCLE_MS * 4), b.clock.advance(CYCLE_MS * 4)]);

    assert.equal(bannerA.active, true);
    assert.equal(bannerB.active, true, 'the losing tab still shows the offer');
    assert.ok(a.bursts >= 4, 'the owner keeps ringing');
    assert.equal(b.bursts, 0, 'the other tab stays silent');
    assert.equal(locks.heldCount, 1);
});

test('when the owner\'s offer clears the lock is released and the other tab takes over', async () => {
    const locks = makeLocks();
    const a = openTab(locks);
    const b = openTab(locks);
    a.banner();
    b.banner();

    a.offers(1);
    await flush();
    b.offers(1);
    await flush();
    assert.equal(b.bursts, 0);

    a.offers(0); // accepted/declined/expired in tab A
    await flush();
    assert.equal(locks.heldCount, 0, 'stopping the ring released the lock');

    await b.clock.advance(CYCLE_MS + 100);
    assert.ok(b.bursts >= 1, 'the remaining tab picks the ring up within a cycle');
});

test('closing the owning tab (destroy) hands the ring to another tab', async () => {
    const locks = makeLocks();
    const a = openTab(locks);
    const b = openTab(locks);
    const bannerA = a.banner();
    b.banner();

    a.offers(1);
    await flush();
    b.offers(1);
    await flush();

    bannerA.destroy(); // wire:navigate body swap / tab close
    await flush();
    assert.equal(locks.heldCount, 0);

    await b.clock.advance(CYCLE_MS + 100);
    assert.ok(b.bursts >= 1);
});

test('a tab whose audio is blocked never takes the lock from one that can sound', async () => {
    const locks = makeLocks();
    const muted = openTab(locks, { audio: 'suspended' });
    const loud = openTab(locks);
    muted.banner();
    loud.banner();

    muted.offers(1);
    await flush();
    await muted.clock.advance(CYCLE_MS * 2);
    assert.equal(locks.heldCount, 0, 'a tab that cannot make sound must not hold the lock');
    assert.equal(muted.bursts, 0);

    loud.offers(1);
    await flush();
    assert.ok(loud.bursts >= 1);
});

test('without Web Locks every tab rings, exactly as before', async () => {
    const a = openTab(null);
    const b = openTab(null);
    a.banner();
    b.banner();

    a.offers(1);
    b.offers(1);
    await flush();

    assert.ok(a.bursts >= 1);
    assert.ok(b.bursts >= 1);
});

test('repeated events never stack a second ring timer or a second lock', async () => {
    const locks = makeLocks();
    const tab = openTab(locks);
    tab.banner();

    for (let i = 0; i < 6; i++) { tab.offers(1); await flush(); }
    const timersWithSixEvents = tab.clock.active;

    tab.offers(1);
    await flush();

    assert.equal(tab.clock.active, timersWithSixEvents, 'no timer per event');
    assert.equal(locks.heldCount, 1);
});

test('navigating repeatedly (mount/destroy) leaves no timers, listeners or lock behind', async () => {
    const locks = makeLocks();
    const tab = openTab(locks);

    for (let i = 0; i < 5; i++) {
        const banner = tab.banner();
        tab.offers(1);
        await flush();
        banner.destroy();
        await flush();
    }

    assert.equal(tab.clock.active, 0, 'every ring/ticker timer cleared');
    assert.equal(locks.heldCount, 0, 'lock released');
    for (const type of ['provider-alert-offers', 'provider-alert-status', 'provider-alert-audio', 'pagehide']) {
        assert.equal(tab.listenerCount(type), 0, `${type} listener removed by destroy()`);
    }

    // …and one live banner has exactly one of each (no duplicate listeners).
    tab.banner();
    for (const type of ['provider-alert-offers', 'provider-alert-status', 'provider-alert-audio', 'pagehide']) {
        assert.equal(tab.listenerCount(type), 1, `${type}: exactly one listener per mounted banner`);
    }
});

test('a lock granted synchronously (re-entering the ring cycle) still leaves one timer', async () => {
    const locks = makeLocks({ sync: true });
    const tab = openTab(locks);
    const banner = tab.banner();

    tab.offers(1);
    await flush();
    const ringTimers = tab.clock.active;

    banner.destroy();
    await flush();

    assert.equal(tab.clock.active, 0, `no ring timer survives destroy() (had ${ringTimers} live)`);
    assert.equal(locks.heldCount, 0);
});
