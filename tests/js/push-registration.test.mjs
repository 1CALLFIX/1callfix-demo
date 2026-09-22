/*
 | Service-worker registration lifecycle (resources/js/push-notifications.js)
 | and the click handler (public/firebase-messaging-sw.js).
 |
 | Run:  node --test tests/js/push-registration.test.mjs
 | (ProviderAlertRoutingJsTest runs this file under `php artisan test`.)
 |
 | Pinned here (1CF-FIX-ALERT-002 phase 4):
 |   - getToken() is never called until the service worker is ACTIVE — calling
 |     it on a still-installing worker is what logged
 |     "PushManager: Subscription failed - no active Service Worker";
 |   - one registration per page however many callers ask for it, and a failed
 |     activation is retried by the next call rather than cached;
 |   - the worker is registered from the web root with scope "/";
 |   - a notification click always lands on the origin the worker runs on,
 |     keeping path + query (the offer id), and existing behaviour is intact
 |     (requireInteraction, focus an open tab, else open a window).
 |
 | Both real sources are loaded into a sandbox. push-notifications.js is an ES
 | module importing the Firebase SDK, so its two import lines are swapped for
 | stubs and `import.meta.env` for a plain object before it is run.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const flush = () => new Promise((resolve) => setImmediate(resolve));

/* ------------------------------ registration ------------------------------ */

function fakeWorker(state = 'installing') {
    const handlers = [];

    return {
        state,
        addEventListener(type, fn) { if (type === 'statechange') handlers.push(fn); },
        become(next) { this.state = next; handlers.forEach((fn) => fn()); },
    };
}

function bootPush({ registration } = {}) {
    const calls = { register: [], getToken: [], fetch: [] };
    const timers = [];
    let tokenSawState = null;

    const reg = registration || { active: null, installing: fakeWorker(), waiting: null };
    const navigatorStub = {
        serviceWorker: {
            register: async (url, options) => { calls.register.push({ url, options }); return reg; },
        },
    };
    const win = { Notification: { permission: 'granted' } };
    const sandbox = {
        window: win,
        document: { addEventListener() {}, querySelector: () => ({ content: 'csrf' }), cookie: '' },
        navigator: navigatorStub,
        Notification: { permission: 'granted', requestPermission: async () => 'granted' },
        fetch: async (url, init) => { calls.fetch.push({ url, init }); return { ok: true }; },
        URLSearchParams,
        URL,
        setTimeout: (fn, ms) => { timers.push({ fn, ms }); return timers.length; },
        clearTimeout: (id) => { if (timers[id - 1]) timers[id - 1].cleared = true; },
        Error,
        Promise,
        __env: {
            VITE_FIREBASE_API_KEY: 'key', VITE_FIREBASE_AUTH_DOMAIN: 'x.firebaseapp.com', VITE_FIREBASE_PROJECT_ID: 'proj',
            VITE_FIREBASE_APP_ID: 'app', VITE_FIREBASE_MESSAGING_SENDER_ID: '1', VITE_FIREBASE_STORAGE_BUCKET: 'b',
            VITE_FIREBASE_VAPID_KEY: 'vapid',
        },
        // Firebase SDK stand-ins (what the two import lines provided).
        initializeApp: () => ({}),
        getApps: () => [],
        getMessaging: () => ({}),
        isSupported: async () => true,
        deleteToken: async () => true,
        getToken: async (m, options) => {
            calls.getToken.push(options);
            tokenSawState = { active: Boolean(options.serviceWorkerRegistration.active), workerState: reg.installing?.state };

            return 'fcm-token';
        },
    };
    sandbox.Notification.permission = 'granted';

    const source = readFileSync(path.join(ROOT, 'resources/js/push-notifications.js'), 'utf8')
        .replace(/^import .*$/gm, '')
        .replace(/import\.meta\.env\./g, '__env.')
        .replace(/^export (async )?function/gm, '$1function');
    vm.runInContext(source, vm.createContext(sandbox), { filename: 'push-notifications.js' });

    return {
        calls,
        timers,
        reg,
        api: win.pushNotifications,
        get tokenSawState() { return tokenSawState; },
    };
}

test('getToken() waits for the installing worker to activate', async () => {
    const h = bootPush();
    const pending = h.api.enable();

    await flush();
    assert.equal(h.calls.register.length, 1, 'registered');
    assert.equal(h.calls.getToken.length, 0, 'no token request while the worker is still installing');

    h.reg.installing.become('installed');
    await flush();
    assert.equal(h.calls.getToken.length, 0, 'installed is not active either');

    h.reg.installing.become('activated');
    assert.equal(await pending, true);
    assert.equal(h.calls.getToken.length, 1);
    assert.equal(h.calls.fetch[0].url, '/push/token', 'the token is stored server-side');
});

test('an already-active registration is used immediately', async () => {
    const h = bootPush({ registration: { active: fakeWorker('activated'), installing: null, waiting: null } });

    assert.equal(await h.api.enable(), true);
    assert.equal(h.calls.getToken.length, 1);
    assert.equal(h.tokenSawState.active, true);
});

test('the worker is registered from the web root with scope "/"', async () => {
    const h = bootPush({ registration: { active: fakeWorker('activated'), installing: null, waiting: null } });
    await h.api.enable();

    const { url, options } = h.calls.register[0];
    assert.ok(url.startsWith('/firebase-messaging-sw.js?'), `served from the root, got ${url}`);
    assert.equal(options.scope, '/');
});

test('concurrent callers share one registration', async () => {
    const h = bootPush();
    const a = h.api.enable();
    const b = h.api.enable();
    const c = h.api.enable();
    await flush();

    h.reg.installing.become('activated');
    assert.deepEqual(await Promise.all([a, b, c]), [true, true, true]);
    assert.equal(h.calls.register.length, 1, 'register() ran once for three callers');
});

test('a worker that fails to install rejects, and the next call tries again', async () => {
    const h = bootPush();
    const first = h.api.enable();
    first.catch(() => {});
    await flush();

    h.reg.installing.become('redundant');
    await assert.rejects(first, /install failed/);

    // The failure was not cached: a new attempt registers again.
    h.reg.installing = fakeWorker();
    const second = h.api.enable();
    await flush();
    assert.equal(h.calls.register.length, 2);
    h.reg.installing.become('activated');
    assert.equal(await second, true);
});

test('a worker that never activates times out instead of hanging', async () => {
    const h = bootPush();
    const pending = h.api.enable();
    pending.catch(() => {});
    await flush();

    const timeout = h.timers.find((t) => t.ms === 10000 && !t.cleared);
    assert.ok(timeout, 'a 10 s activation timeout is armed');
    timeout.fn();

    await assert.rejects(pending, /did not activate/);
    assert.equal(h.calls.getToken.length, 0);
});

/* ------------------------- service worker click handler ------------------------- */

function bootSw({ windows = [] } = {}) {
    const handlers = {};
    const shown = [];
    const opened = [];
    let background = null;

    const clientsApi = {
        claim: async () => {},
        matchAll: async () => windows,
        openWindow: async (url) => { opened.push(url); },
    };
    const self = {
        location: new URL('https://api.1callfix.com/firebase-messaging-sw.js?apiKey=k&projectId=p&messagingSenderId=1&appId=a'),
        registration: { showNotification: (title, options) => { shown.push({ title, options }); } },
        addEventListener: (type, fn) => { handlers[type] = fn; },
        clients: clientsApi,
    };
    const sandbox = {
        self,
        clients: clientsApi,
        URL,
        importScripts() {},
        firebase: {
            initializeApp() {},
            messaging: () => ({ onBackgroundMessage: (fn) => { background = fn; } }),
        },
    };
    vm.runInContext(readFileSync(path.join(ROOT, 'public/firebase-messaging-sw.js'), 'utf8'), vm.createContext(sandbox), { filename: 'firebase-messaging-sw.js' });

    const click = async (link) => {
        let work = Promise.resolve();
        handlers.notificationclick({
            notification: { close() {}, data: link === undefined ? undefined : { link } },
            waitUntil: (p) => { work = p; },
        });
        await work;
    };

    return { shown, opened, click, background: (payload) => background(payload) };
}

test('a background message keeps requireInteraction and carries the link through', () => {
    const sw = bootSw();
    sw.background({ data: { title: 'New job offer', body: 'AC repair', link: 'https://api.1callfix.com/provider/jobs?offer=7' } });

    assert.equal(sw.shown.length, 1);
    assert.equal(sw.shown[0].title, 'New job offer');
    assert.equal(sw.shown[0].options.requireInteraction, true);
    assert.equal(sw.shown[0].options.data.link, 'https://api.1callfix.com/provider/jobs?offer=7');
});

test('a click with no open window opens the offer link on the worker\'s own origin', async () => {
    const sw = bootSw();
    await sw.click('https://api.1callfix.com/provider/jobs?offer=7');

    assert.deepEqual(sw.opened, ['https://api.1callfix.com/provider/jobs?offer=7']);
});

test('a link built for a different host is pinned to the origin the user is signed in on', async () => {
    const sw = bootSw();
    await sw.click('https://some-other-host.example/provider/jobs?offer=7');

    assert.deepEqual(sw.opened, ['https://api.1callfix.com/provider/jobs?offer=7']);
});

test('a relative link resolves against the worker origin', async () => {
    const sw = bootSw();
    await sw.click('/provider/jobs?offer=3');

    assert.deepEqual(sw.opened, ['https://api.1callfix.com/provider/jobs?offer=3']);
});

test('an open tab is focused and navigated instead of opening another window', async () => {
    const seen = [];
    const client = { focus: async () => { seen.push('focus'); }, navigate: async (url) => { seen.push(url); } };
    const sw = bootSw({ windows: [client] });
    await sw.click('https://api.1callfix.com/provider/jobs?offer=9');

    assert.deepEqual(seen, ['focus', 'https://api.1callfix.com/provider/jobs?offer=9']);
    assert.deepEqual(sw.opened, [], 'no second window');
});

test('a missing or unusable link falls back to the site root, never throws', async () => {
    const sw = bootSw();
    await sw.click(undefined);
    await sw.click('http://[bad');

    assert.deepEqual(sw.opened, ['https://api.1callfix.com/', 'https://api.1callfix.com/']);
});
