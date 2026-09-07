/*
 | Firebase Cloud Messaging background handler (Phase 2 provider push).
 |
 | Served from the web root so its scope is "/". It is registered manually
 | by resources/js/push-notifications.js (provider + customer) and
 | public/js/admin-push.js (admin) with the PUBLIC Firebase web config
 | passed as query-string params — so this file holds no config of its own
 | and can never drift from config/services.php → services.firebase.web.
 |
 | A service worker cannot use the ESM Firebase build reliably, so this
 | pulls the compat SDK from gstatic (Google's own CDN, the documented FCM
 | web pattern). Pin the version to match `firebase` in package.json.
 |
 | The server (App\Notifications\Adapters\FirebaseFcmPushAdapter) sends
 | DATA-ONLY messages, so onBackgroundMessage below is the single path that
 | renders a notification — which is what lets us force requireInteraction
 | and a click-through deep link. See that adapter's docblock.
 */
importScripts('https://www.gstatic.com/firebasejs/12.18.0/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/12.18.0/firebase-messaging-compat.js');

const params = new URL(self.location).searchParams;

const firebaseConfig = {
    apiKey: params.get('apiKey'),
    authDomain: params.get('authDomain'),
    projectId: params.get('projectId'),
    messagingSenderId: params.get('messagingSenderId'),
    appId: params.get('appId'),
};

if (firebaseConfig.projectId && firebaseConfig.apiKey) {
    firebase.initializeApp(firebaseConfig);
    const messaging = firebase.messaging();

    messaging.onBackgroundMessage((payload) => {
        const data = payload.data || {};
        const title = data.title || 'New notification';
        const options = {
            body: data.body || '',
            tag: data.tag || 'onecallfix',
            data: { link: data.link || '/' },
            // Android + desktop keep the notification on screen until the
            // user acts on it. iOS Safari ignores this (platform limit).
            requireInteraction: true,
            renotify: true,
            icon: '/icons/icon.svg',
            badge: '/icons/icon.svg',
        };
        self.registration.showNotification(title, options);
    });
}

// Clicking the notification: focus an already-open app tab and navigate it
// to the target, otherwise open a new window there.
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const link = (event.notification.data && event.notification.data.link) || '/';

    event.waitUntil((async () => {
        const windows = await clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (const client of windows) {
            if ('focus' in client) {
                await client.focus();
                if (link && 'navigate' in client) {
                    try { await client.navigate(link); } catch (e) { /* cross-origin / not allowed — ignore */ }
                }
                return;
            }
        }
        if (clients.openWindow) {
            await clients.openWindow(link);
        }
    })());
});

// Take control of open pages as soon as an updated SW activates, so a
// deploy doesn't need a full tab close to pick up handler changes.
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));
