/*
 | Admin "order alerts" push registration (Phase 2).
 |
 | Framework-free on purpose: the admin layout has no Vite pipeline, it
 | loads plain <script> tags (Trix, Google Maps, zone-map.js). This mirrors
 | resources/js/push-notifications.js but uses the Firebase COMPAT CDN SDK
 | that resources/views/layouts/admin.blade.php loads just above this file.
 |
 | Public Firebase config + the VAPID key are injected by the layout as
 | window.__ADMIN_PUSH_CONFIG (server-rendered from config/services.php →
 | services.firebase.web). With it absent/blank every entry point no-ops.
 |
 | Scope: an admin who opts in gets App\Notifications\AdminOpsAlertNotification
 | on booking-created / payment-captured — nothing else. The opt-in state
 | is users.push_ops_alerts, flipped via POST /admin/push/ops-alerts.
 */
(function () {
    const cfg = window.__ADMIN_PUSH_CONFIG || {};
    const firebaseConfig = {
        apiKey: cfg.apiKey,
        authDomain: cfg.authDomain,
        projectId: cfg.projectId,
        messagingSenderId: cfg.messagingSenderId,
        appId: cfg.appId,
    };
    const vapidKey = cfg.vapidKey;

    const SW_URL = '/firebase-messaging-sw.js?' + new URLSearchParams({
        apiKey: firebaseConfig.apiKey || '',
        authDomain: firebaseConfig.authDomain || '',
        projectId: firebaseConfig.projectId || '',
        messagingSenderId: firebaseConfig.messagingSenderId || '',
        appId: firebaseConfig.appId || '',
    }).toString();

    function configured() {
        return Boolean(firebaseConfig.apiKey && firebaseConfig.projectId && vapidKey
            && window.firebase && 'serviceWorker' in navigator && 'Notification' in window);
    }

    function csrf() {
        return document.querySelector('meta[name="csrf-token"]')?.content || '';
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
    function ensureMessaging() {
        if (!configured()) return null;
        if (!messaging) {
            if (!window.firebase.apps.length) window.firebase.initializeApp(firebaseConfig);
            messaging = window.firebase.messaging();
        }
        return messaging;
    }

    async function enable() {
        const m = ensureMessaging();
        if (!m) return { ok: false, reason: 'unsupported' };

        const permission = await Notification.requestPermission();
        if (permission !== 'granted') return { ok: false, reason: 'denied' };

        const registration = await navigator.serviceWorker.register(SW_URL, { scope: '/' });
        const token = await m.getToken({ vapidKey, serviceWorkerRegistration: registration });
        if (!token) return { ok: false, reason: 'no-token' };

        const tokenRes = await post('/push/token', { token });
        if (!tokenRes.ok) return { ok: false, reason: 'token-store-failed' };

        const prefRes = await post('/admin/push/ops-alerts', { enabled: true });
        return { ok: prefRes.ok, reason: prefRes.ok ? 'enabled' : 'pref-save-failed' };
    }

    async function disable() {
        const m = ensureMessaging();
        if (m) { try { await m.deleteToken(); } catch (e) { /* ignore */ } }
        try {
            await fetch('/push/token', {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
        } catch (e) { /* ignore */ }
        const res = await post('/admin/push/ops-alerts', { enabled: false });
        return { ok: res.ok };
    }

    async function refreshIfGranted() {
        if (!configured() || Notification.permission !== 'granted') return;
        try {
            const m = ensureMessaging();
            if (!m) return;
            const registration = await navigator.serviceWorker.register(SW_URL, { scope: '/' });
            const token = await m.getToken({ vapidKey, serviceWorkerRegistration: registration });
            if (token) await post('/push/token', { token });
        } catch (e) { /* transient — retry next load */ }
    }

    window.adminPush = { enable, disable, isSupported: () => configured() };
    document.addEventListener('DOMContentLoaded', refreshIfGranted);
})();
