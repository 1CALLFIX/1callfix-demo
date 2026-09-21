/*
 | Web push registration (Phase 2) for the provider and customer web apps.
 |
 | Companion to public/firebase-messaging-sw.js. This module's only job:
 | on a real user gesture, get an FCM web token and POST it to
 | /push/token so the server can push to this browser even when it is
 | closed. Public Firebase config comes from Vite env (VITE_FIREBASE_*),
 | same as resources/js/customer-auth.js. With config absent (local dev)
 | every entry point is an inert no-op, never a throw.
 |
 | Nothing here is trusted server-side — the token is re-validated by FCM
 | on every send, and an invalid one is cleared by PushChannel.
 |
 | Admin uses a separate framework-free build (public/js/admin-push.js)
 | because the admin layout has no Vite pipeline.
 */
import { initializeApp, getApps } from 'firebase/app';
import { getMessaging, getToken, deleteToken, isSupported } from 'firebase/messaging';

const config = {
    apiKey: import.meta.env.VITE_FIREBASE_API_KEY,
    authDomain: import.meta.env.VITE_FIREBASE_AUTH_DOMAIN,
    projectId: import.meta.env.VITE_FIREBASE_PROJECT_ID,
    appId: import.meta.env.VITE_FIREBASE_APP_ID,
    messagingSenderId: import.meta.env.VITE_FIREBASE_MESSAGING_SENDER_ID,
    storageBucket: import.meta.env.VITE_FIREBASE_STORAGE_BUCKET,
};

const vapidKey = import.meta.env.VITE_FIREBASE_VAPID_KEY;

// The SW self-configures from these query params (see its header comment),
// so the config has exactly one source: this bundle's env.
const SW_URL = '/firebase-messaging-sw.js?' + new URLSearchParams({
    apiKey: config.apiKey || '',
    authDomain: config.authDomain || '',
    projectId: config.projectId || '',
    messagingSenderId: config.messagingSenderId || '',
    appId: config.appId || '',
}).toString();

function configured() {
    return Boolean(config.apiKey && config.projectId && vapidKey);
}

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content
        || document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] && decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)[1])
        || '';
}

async function post(url, body) {
    return fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        body: JSON.stringify(body || {}),
    });
}

let messaging = null;

async function ensureMessaging() {
    if (!configured()) return null;
    if (!(await isSupported().catch(() => false))) return null;
    if (!messaging) {
        const app = getApps().length ? getApps()[0] : initializeApp(config);
        messaging = getMessaging(app);
    }
    return messaging;
}

const SW_ACTIVATE_TIMEOUT_MS = 10000;

/** Resolves once `worker` is activated; rejects if it fails or never gets there. */
function whenActivated(worker) {
    return new Promise((resolve, reject) => {
        if (worker.state === 'activated') return resolve();
        const timer = setTimeout(() => reject(new Error('Service worker did not activate in time.')), SW_ACTIVATE_TIMEOUT_MS);
        worker.addEventListener('statechange', () => {
            if (worker.state === 'activated') { clearTimeout(timer); resolve(); }
            else if (worker.state === 'redundant') { clearTimeout(timer); reject(new Error('Service worker install failed.')); }
        });
    });
}

let registrationPromise = null;

/**
 * The ACTIVE service-worker registration. register() resolves as soon as the
 * worker is installing, and FCM's getToken() then fails with "no active
 * Service Worker" on a first visit — so wait for activation before handing
 * the registration on. One promise per page: repeated calls (opt-in click,
 * silent refresh on load) reuse the same registration instead of racing to
 * register again. A failure is not cached, so the next call retries.
 */
function currentRegistration() {
    if (!registrationPromise) {
        registrationPromise = (async () => {
            const registration = await navigator.serviceWorker.register(SW_URL, { scope: '/' });
            if (!registration.active) {
                const pending = registration.installing || registration.waiting;
                if (pending) await whenActivated(pending);
            }
            return registration;
        })().catch((e) => {
            registrationPromise = null;
            throw e;
        });
    }
    return registrationPromise;
}

export async function isPushSupported() {
    return configured()
        && 'serviceWorker' in navigator
        && 'Notification' in window
        && (await isSupported().catch(() => false));
}

/**
 * Call from a real click. Requests permission, registers the SW, gets a
 * token and stores it server-side. Returns true on success.
 */
export async function enablePush() {
    const m = await ensureMessaging();
    if (!m) return false;

    const permission = await Notification.requestPermission();
    if (permission !== 'granted') return false;

    const registration = await currentRegistration();
    const token = await getToken(m, { vapidKey, serviceWorkerRegistration: registration });
    if (!token) return false;

    const res = await post('/push/token', { token });
    return res.ok;
}

/** Turn it off: delete the token client-side and clear it server-side. */
export async function disablePush() {
    const m = await ensureMessaging();
    if (m) { try { await deleteToken(m); } catch (e) { /* ignore */ } }
    try {
        await fetch('/push/token', {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
    } catch (e) { /* ignore */ }
}

/**
 * Silent, no prompt: if the user already granted permission on a previous
 * visit, refresh the token (they rotate) and re-store it. Safe to call on
 * every page load.
 */
export async function refreshPushTokenIfGranted() {
    if (!(await isPushSupported())) return;
    if (Notification.permission !== 'granted') return;
    try {
        const m = await ensureMessaging();
        if (!m) return;
        const registration = await currentRegistration();
        const token = await getToken(m, { vapidKey, serviceWorkerRegistration: registration });
        if (token) await post('/push/token', { token });
    } catch (e) { /* offline / transient — try again next load */ }
}

// Expose for inline @click handlers on the opt-in buttons.
window.pushNotifications = {
    enable: enablePush,
    disable: disablePush,
    isSupported: isPushSupported,
};

document.addEventListener('DOMContentLoaded', () => { refreshPushTokenIfGranted(); });
