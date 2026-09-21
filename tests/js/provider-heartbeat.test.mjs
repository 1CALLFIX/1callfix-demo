/*
 | Behaviour of the provider location heartbeat (Alpine.data 'providerHeartbeat'
 | in resources/js/provider-alerts.js).
 |
 | Run:  node --test tests/js/provider-heartbeat.test.mjs
 | (ProviderHeartbeatLeakTest runs this same file under `php artisan test`.)
 |
 | Regression pinned here (1CF-HEARTBEAT-LEAK-FIX-001): the heartbeat used to be
 | an inline `x-init="setInterval(...)"` that was never cleared. Each render of
 | the marker (header chip, its drawer twin, the Dashboard card), each
 | wire:navigate, and going offline left an interval running that kept calling
 | `$wire.goOnline` — flipping the provider back online. The interval now belongs
 | to ONE page-wide set of members; it exists only while that set is non-empty.
 |
 | The real module source is loaded into a sandbox. Only the browser edges are
 | fakes: a manual clock, a navigator.geolocation whose fixes the test resolves
 | by hand, and a minimal Alpine that records what the module registers. Alpine
 | calls the x-data factory with NO arguments, exposes magics (`$wire`) on
 | `this`, and invokes init()/destroy() — the harness does the same.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const SRC = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../resources/js/provider-alerts.js');
const HEARTBEAT_MS = 120000;

function makeClock() {
    let now = 0;
    let nextId = 1;
    let created = 0;
    const timers = new Map();
    const add = (fn, ms, repeat) => {
        const id = nextId++;
        created += 1;
        timers.set(id, { fn, ms, repeat, due: now + ms });
        return id;
    };

    return {
        setInterval: (fn, ms) => add(fn, ms, true),
        setTimeout: (fn, ms) => add(fn, ms, false),
        clearInterval: (id) => { timers.delete(id); },
        clearTimeout: (id) => { timers.delete(id); },
        advance(ms) {
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
            }
            now = end;
        },
        /** Live timers/intervals. Only the heartbeat creates any in this harness. */
        get active() { return timers.size; },
        /** Every timer ever created (leaks show up here even after clears). */
        get created() { return created; },
        /** Every live interval's period. */
        get periods() { return [...timers.values()].map((t) => t.ms); },
    };
}

function boot({ geolocation = true } = {}) {
    const clock = makeClock();
    const registry = {};
    const listeners = { add: 0, remove: 0 };
    const doc = {
        hidden: false,
        addEventListener() { listeners.add += 1; },
        removeEventListener() { listeners.remove += 1; },
    };

    /** navigator.geolocation: every request waits until the test resolves it. */
    const fixes = [];
    const nav = geolocation
        ? {
            geolocation: {
                getCurrentPosition(ok, fail, options) { fixes.push({ ok, fail, options }); },
            },
        }
        : {};

    const win = {
        addEventListener() { listeners.add += 1; },
        removeEventListener() { listeners.remove += 1; },
        dispatchEvent() {},
        Alpine: { data(name, factory) { registry[name] = factory; } },
        setInterval: clock.setInterval,
        clearInterval: clock.clearInterval,
        setTimeout: clock.setTimeout,
        clearTimeout: clock.clearTimeout,
    };
    const sandbox = {
        window: win,
        document: doc,
        navigator: nav,
        MutationObserver: class { observe() {} disconnect() {} },
        console,
    };
    vm.runInContext(readFileSync(SRC, 'utf8'), vm.createContext(sandbox), { filename: 'provider-alerts.js' });

    assert.equal(typeof registry.providerHeartbeat, 'function', 'providerHeartbeat must be registered');

    return { clock, registry, doc, fixes, listeners };
}

/** Mount one marker the way Alpine does: factory() with no args, magics on `this`, then init(). */
function mount(h) {
    const calls = [];
    const data = h.registry.providerHeartbeat();
    data.$wire = { goOnline: (lat, lng) => calls.push([lat, lng]) };
    data.init();

    return { data, calls, destroy: () => data.destroy() };
}

/** Deliver a geolocation fix to the oldest pending request. */
function resolveFix(h, lat = 12.97, lng = 77.59) {
    const f = h.fixes.shift();
    assert.ok(f, 'expected a pending geolocation request');
    f.ok({ coords: { latitude: lat, longitude: lng } });
}

test('the x-data factory takes no argument', () => {
    assert.equal(boot().registry.providerHeartbeat.length, 0);
});

test('A. one marker creates exactly one 120 s interval and reports its fix', () => {
    const h = boot();
    const m = mount(h);

    assert.equal(h.clock.active, 1);
    assert.deepEqual(h.clock.periods, [HEARTBEAT_MS]);

    h.clock.advance(HEARTBEAT_MS - 1);
    assert.equal(h.fixes.length, 0, 'no tick before 120 s (there is no immediate call)');

    h.clock.advance(1);
    assert.equal(h.fixes.length, 1);
    assert.equal(h.fixes[0].options.timeout, 8000, 'geolocation timeout unchanged');
    resolveFix(h, 12.5, 77.5);
    assert.deepEqual(m.calls, [[12.5, 77.5]], 'goOnline(lat, lng) unchanged');
});

test('B. header chip + drawer twin + Dashboard card share ONE interval and send ONE report per tick', () => {
    const h = boot();
    const a = mount(h);
    const b = mount(h);
    const c = mount(h);

    assert.equal(h.clock.active, 1);
    assert.equal(h.clock.created, 1);

    h.clock.advance(HEARTBEAT_MS);
    assert.equal(h.fixes.length, 1, 'one geolocation request, not three');
    resolveFix(h);
    assert.equal(a.calls.length + b.calls.length + c.calls.length, 1, 'one goOnline request, not three');
});

test('C. re-running init() on the same instance does not add a second interval or a second report', () => {
    const h = boot();
    const m = mount(h);
    m.data.init();
    m.data.init();

    assert.equal(h.clock.active, 1);
    assert.equal(h.clock.created, 1);

    h.clock.advance(HEARTBEAT_MS);
    resolveFix(h);
    assert.equal(m.calls.length, 1);
});

test('D. destroying the last marker clears the interval; earlier ones keep it alive', () => {
    const h = boot();
    const a = mount(h);
    const b = mount(h);

    a.destroy();
    assert.equal(h.clock.active, 1, 'a remaining marker keeps the heartbeat');

    b.destroy();
    assert.equal(h.clock.active, 0, 'last marker gone -> interval cleared');
});

test('E. going offline stops the heartbeat: no geolocation request and no goOnline, ever', () => {
    const h = boot();
    const chip = mount(h);
    const twin = mount(h);
    const card = mount(h);

    // Server re-render removes every marker -> Alpine destroys each.
    chip.destroy();
    twin.destroy();
    card.destroy();

    h.clock.advance(HEARTBEAT_MS * 10);
    assert.equal(h.clock.active, 0);
    assert.equal(h.fixes.length, 0);
    assert.equal(chip.calls.length + twin.calls.length + card.calls.length, 0);
});

test('F. a fix that resolves AFTER going offline is dropped (cannot flip the provider back online)', () => {
    const h = boot();
    const m = mount(h);

    h.clock.advance(HEARTBEAT_MS);
    assert.equal(h.fixes.length, 1, 'request in flight');

    m.destroy(); // provider tapped Go offline while the fix was resolving
    resolveFix(h);

    assert.equal(m.calls.length, 0);
});

test('G. wire:navigate (destroy all, mount fresh) never accumulates intervals', () => {
    const h = boot();
    for (let visit = 0; visit < 25; visit += 1) {
        const chip = mount(h);
        const twin = mount(h);
        const card = mount(h);
        assert.equal(h.clock.active, 1, `visit ${visit}: exactly one live interval`);
        chip.destroy();
        twin.destroy();
        card.destroy();
        assert.equal(h.clock.active, 0, `visit ${visit}: none left after leaving`);
    }
    // 25 visits => 25 intervals created and 25 cleared; nothing outstanding.
    assert.equal(h.clock.created, 25);
    assert.equal(h.clock.active, 0);
});

test('H. Livewire morph that replaces the marker (new instance mounts, old one destroys) keeps exactly one interval', () => {
    const h = boot();
    const old = mount(h);
    const fresh = mount(h); // morph: new element initialises first...
    old.destroy(); //          ...then the old one is torn down

    assert.equal(h.clock.active, 1);

    h.clock.advance(HEARTBEAT_MS);
    resolveFix(h);
    assert.equal(old.calls.length, 0);
    assert.equal(fresh.calls.length, 1, 'the report goes through the surviving marker');
});

test('I. the oldest marker leaving hands reporting to the next one', () => {
    const h = boot();
    const first = mount(h);
    const second = mount(h);
    first.destroy();

    h.clock.advance(HEARTBEAT_MS);
    resolveFix(h);
    assert.equal(first.calls.length, 0);
    assert.equal(second.calls.length, 1);
});

test('J. a hidden tab or a browser without geolocation skips the tick (guards unchanged)', () => {
    const h = boot();
    const m = mount(h);
    h.doc.hidden = true;
    h.clock.advance(HEARTBEAT_MS);
    assert.equal(h.fixes.length, 0);
    assert.equal(m.calls.length, 0);

    h.doc.hidden = false;
    h.clock.advance(HEARTBEAT_MS);
    assert.equal(h.fixes.length, 1, 'resumes when visible again');

    const none = boot({ geolocation: false });
    const n = mount(none);
    none.clock.advance(HEARTBEAT_MS * 3);
    assert.equal(n.calls.length, 0);
});

test('K. a denied / failed geolocation request reports nothing and keeps the heartbeat alive', () => {
    const h = boot();
    const m = mount(h);

    h.clock.advance(HEARTBEAT_MS);
    h.fixes.shift().fail({ code: 1 });
    assert.equal(m.calls.length, 0);
    assert.equal(h.clock.active, 1);

    h.clock.advance(HEARTBEAT_MS);
    resolveFix(h);
    assert.equal(m.calls.length, 1);
});

test('L. mounting and destroying markers adds no window/document event listeners (nothing to duplicate)', () => {
    const h = boot();
    const before = { ...h.listeners };

    for (let i = 0; i < 10; i += 1) {
        const m = mount(h);
        h.clock.advance(HEARTBEAT_MS);
        m.destroy();
    }

    assert.deepEqual(h.listeners, before);
});

test('M. after the set empties, a later online session starts a fresh single interval', () => {
    const h = boot();
    mount(h).destroy();
    assert.equal(h.clock.active, 0);

    const again = mount(h);
    assert.equal(h.clock.active, 1);
    h.clock.advance(HEARTBEAT_MS);
    resolveFix(h);
    assert.equal(again.calls.length, 1);
});
