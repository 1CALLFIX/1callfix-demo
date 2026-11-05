/*
 | Inside the 1CallFix Android apps (mobile/, Capacitor shells over this site) only.
 | In a normal browser window.Capacitor is absent and this file does nothing.
 |
 | Its one job: once someone is signed in, ask for notification permission and send the phone's push token
 | to the existing POST /push/token (the same endpoint web push uses). The app shows the pushes itself
 | (mobile/android/.../PushService.java), so nothing else on the site changes.
 */

const cap = window.Capacitor;

function inApp() {
    return Boolean(cap && typeof cap.isNativePlatform === 'function' && cap.isNativePlatform());
}

function signedInUser() {
    return document.querySelector('meta[name="cf-signed-in"]')?.content || '';
}

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

function remembered(key) {
    try { return sessionStorage.getItem(key); } catch (e) { return null; }
}

function remember(key, value) {
    try { sessionStorage.setItem(key, value); } catch (e) { /* private mode: we just post again next time */ }
}

let listening = false;

async function registerPush() {
    const Push = cap.Plugins?.PushNotifications;
    const user = signedInUser();
    if (!Push || !user) return;

    try {
        let perm = await Push.checkPermissions();
        if (perm.receive === 'prompt' || perm.receive === 'prompt-with-rationale') {
            perm = await Push.requestPermissions();
        }
        if (perm.receive !== 'granted') return;

        if (!listening) {
            listening = true;
            Push.addListener('registration', async ({ value }) => {
                const who = signedInUser();
                const key = 'cf-push-token:' + who;
                if (!who || !value || remembered(key) === value) return;
                const res = await fetch('/push/token', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ token: value }),
                });
                if (res.ok) remember(key, value);
            });
        }
        await Push.register();
    } catch (e) {
        console.warn('[1CallFix app] push registration failed', e);
    }
}

if (inApp()) {
    document.documentElement.classList.add('in-app');
    registerPush();
    // Signing in with Livewire can swap the page without a full reload.
    document.addEventListener('livewire:navigated', registerPush);
}
