/*
 | Behaviour of the Job Offers per-offer countdown pill (Alpine.data
 | 'providerOfferCountdown' in resources/js/provider-alerts.js).
 |
 | Run:  node --test tests/js/provider-offer-countdown.test.mjs
 | (ProviderOfferCountdownTest runs this same file under `php artisan test`.)
 |
 | Regression pinned here (1CF-ALERT-COUNTDOWN-FIX): the pill used to receive
 | the server's seconds as an x-data ARGUMENT. That attribute changes on every
 | wire:poll render, so Alpine rebuilt the component in place while x-text stayed
 | bound to the old scope, and the visible text froze between renders. The
 | seconds now travel in `data-seconds`; the x-data expression is constant and a
 | re-render only rewrites that attribute.
 |
 | The real module source is loaded into a sandbox. Only the browser edges are
 | fakes: a manual clock (setInterval / setTimeout), a MutationObserver that the
 | test fires by hand (a Livewire morph rewriting data-seconds), and a minimal
 | Alpine that records what the module registers. Nothing is mocked inside the
 | component itself. Alpine calls the x-data factory with NO arguments and
 | invokes init()/destroy() on the returned object — the harness does the same.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const SRC = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../resources/js/provider-alerts.js');

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
        /** Live timers/intervals. Only the countdown pills create any in this harness. */
        get active() { return timers.size; },
    };
}

function boot() {
    const clock = makeClock();
    const registry = {};
    const observers = [];

    class FakeMutationObserver {
        constructor(callback) {
            this.callback = callback;
            this.live = false;
            this.options = null;
            observers.push(this);
        }

        observe(el, options) { this.el = el; this.options = options; this.live = true; }

        disconnect() { this.live = false; }

        /** A Livewire morph rewrote an attribute on the observed element. */
        fire() { if (this.live) this.callback([], this); }
    }

    const win = {
        addEventListener() {},
        removeEventListener() {},
        dispatchEvent() {},
        Alpine: { data(name, factory) { registry[name] = factory; } },
        setInterval: clock.setInterval,
        clearInterval: clock.clearInterval,
        setTimeout: clock.setTimeout,
        clearTimeout: clock.clearTimeout,
    };
    const sandbox = {
        window: win,
        document: { addEventListener() {}, removeEventListener() {}, hidden: false },
        navigator: {},
        MutationObserver: FakeMutationObserver,
        console,
    };
    vm.runInContext(readFileSync(SRC, 'utf8'), vm.createContext(sandbox), { filename: 'provider-alerts.js' });

    assert.equal(typeof registry.providerOfferCountdown, 'function', 'providerOfferCountdown must be registered');

    return { clock, registry, observers };
}

/** Mount one pill the way Alpine does: factory() with no args, then $el, then init(). */
function mount(h, seconds) {
    const el = { dataset: { seconds: String(seconds) } };
    const data = h.registry.providerOfferCountdown();
    data.$el = el;
    data.init();

    return {
        el,
        data,
        /** What the pill's x-text expression renders. */
        text: () => `${data.n > 0 ? data.n : 0}s left`,
        /** Livewire re-render: the server writes a new data-seconds; the observer fires. */
        serverRender(seconds) {
            el.dataset.seconds = String(seconds);
            h.observers.filter((o) => o.el === el).forEach((o) => o.fire());
        },
    };
}

test('the x-data factory takes no argument (the seconds live in data-seconds)', () => {
    const h = boot();
    assert.equal(h.registry.providerOfferCountdown.length, 0);
});

test('A. counts down every second from the initial server value', () => {
    const h = boot();
    const p = mount(h, 38);

    assert.equal(p.text(), '38s left');
    const seen = [];
    for (let i = 0; i < 5; i++) {
        h.clock.advance(1000);
        seen.push(p.data.n);
    }
    assert.deepEqual(seen, [37, 36, 35, 34, 33]);
    assert.equal(h.clock.active, 1);
});

test('A. stops its interval by itself at zero and never goes negative', () => {
    const h = boot();
    const p = mount(h, 2);

    h.clock.advance(5000);
    assert.equal(p.data.n, 0);
    assert.equal(p.text(), '0s left');
    assert.equal(h.clock.active, 0);
});

test('A. a missing/garbage/negative data-seconds renders 0 and starts no timer', () => {
    for (const bad of ['', 'abc', '-7', 'NaN']) {
        const h = boot();
        const p = mount(h, bad);
        assert.equal(p.data.n, 0, `seconds=${JSON.stringify(bad)}`);
        assert.equal(h.clock.active, 0);
    }
});

test('B. a server re-render does NOT freeze the countdown (the reported regression)', () => {
    const h = boot();
    const p = mount(h, 38);

    h.clock.advance(3000); // 35 left
    assert.equal(p.data.n, 35);

    p.serverRender(35); // wire:poll render: same second the client already shows
    const seen = [];
    for (let i = 0; i < 6; i++) {
        h.clock.advance(1000);
        seen.push(p.data.n);
    }
    // Was 35,35,35,35,35,35 with x-data="providerOfferCountdown(35)".
    assert.deepEqual(seen, [34, 33, 32, 31, 30, 29]);
    assert.equal(h.clock.active, 1);
});

test('B. a re-render every poll (4 s) keeps it ticking every second across a full offer window', () => {
    const h = boot();
    const p = mount(h, 50);
    const seen = [];

    for (let s = 1; s <= 48; s++) {
        h.clock.advance(1000);
        if (s % 4 === 0) p.serverRender(50 - s); // server agrees with the local clock
        seen.push(p.data.n);
    }
    assert.deepEqual(seen, Array.from({ length: 48 }, (_, i) => 49 - i));
    assert.equal(h.clock.active, 1);
});

test('B. only data-seconds is observed, and only on the pill itself', () => {
    const h = boot();
    const p = mount(h, 10);

    assert.equal(h.observers.length, 1);
    assert.equal(h.observers[0].el, p.el);
    // Compared by value: the options object is built inside the vm context, so
    // its prototype differs from this realm's and deepStrictEqual would object.
    assert.equal(JSON.stringify(h.observers[0].options), JSON.stringify({ attributes: true, attributeFilter: ['data-seconds'] }));
});

test('C. a new server value is picked up (lower, higher, and after the timer had stopped)', () => {
    const h = boot();
    const p = mount(h, 40);

    h.clock.advance(3000); // 37 locally
    p.serverRender(30); // server is behind the local count -> take it
    assert.equal(p.data.n, 30);
    assert.equal(p.text(), '30s left');

    h.clock.advance(2000);
    assert.equal(p.data.n, 28);

    p.serverRender(45); // server extended the window -> take it
    assert.equal(p.data.n, 45);
    h.clock.advance(1000);
    assert.equal(p.data.n, 44);

    // Run out, then the server says there is time left again: it must resume.
    p.serverRender(1);
    h.clock.advance(3000);
    assert.equal(p.data.n, 0);
    assert.equal(h.clock.active, 0);
    p.serverRender(10);
    assert.equal(p.data.n, 10);
    assert.equal(h.clock.active, 1);
    h.clock.advance(2000);
    assert.equal(p.data.n, 8);
});

test('D. destroy() clears the interval, disconnects the observer and freezes the state', () => {
    const h = boot();
    const p = mount(h, 30);

    h.clock.advance(2000);
    assert.equal(h.clock.active, 1);
    assert.equal(h.observers[0].live, true);

    p.data.destroy();
    assert.equal(h.clock.active, 0);
    assert.equal(h.observers[0].live, false);

    h.clock.advance(10000);
    assert.equal(p.data.n, 28, 'no tick after destroy');

    p.serverRender(99); // a late render after teardown must not restart anything
    assert.equal(p.data.n, 28);
    assert.equal(h.clock.active, 0);

    p.data.destroy(); // safe to call twice
    assert.equal(h.clock.active, 0);
});

test('D. destroy() also cleans up a pill whose timer had already stopped at zero', () => {
    const h = boot();
    const p = mount(h, 1);

    h.clock.advance(5000);
    assert.equal(h.clock.active, 0);
    p.data.destroy();
    assert.equal(h.observers[0].live, false);
});

test('E. many re-renders never stack intervals', () => {
    const h = boot();
    const p = mount(h, 500);

    for (let i = 0; i < 200; i++) {
        p.serverRender(500 - i);
        assert.equal(h.clock.active, 1, `after render #${i + 1}`);
    }
    h.clock.advance(1000);
    assert.equal(h.clock.active, 1);
});

test('E. navigation: each page load ends with exactly one interval, and destroyed pills leave none', () => {
    const h = boot();

    for (let page = 0; page < 10; page++) {
        const p = mount(h, 40); // wire:navigate -> new page -> new pill
        assert.equal(h.clock.active, 1, `page ${page + 1}`);
        p.serverRender(39); // a poll render while on that page
        assert.equal(h.clock.active, 1);
        p.data.destroy(); // the old body is destroyed on navigation
        assert.equal(h.clock.active, 0, `page ${page + 1} after destroy`);
    }
    assert.equal(h.observers.filter((o) => o.live).length, 0);
});

test('E. several offers on one page each own exactly one interval', () => {
    const h = boot();
    const pills = [mount(h, 40), mount(h, 30), mount(h, 20)];

    assert.equal(h.clock.active, 3);
    pills.forEach((p) => p.serverRender(15));
    assert.equal(h.clock.active, 3);
    h.clock.advance(1000);
    assert.deepEqual(pills.map((p) => p.data.n), [14, 14, 14]);

    pills.forEach((p) => p.data.destroy());
    assert.equal(h.clock.active, 0);
});
