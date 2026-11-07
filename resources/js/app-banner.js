/**
 * "Get the app" strip on phones (resources/views/components/customer/app-banner.blade.php).
 * Shows only in a phone browser: Android → Play Store button, iPhone/iPad → "Add to Home Screen" steps.
 * Never inside our Android app (Capacitor), never once the website is installed (standalone), never on computers.
 * Closing it hides it on this phone for data-hide-days days (browser storage; if storage is blocked it just shows again).
 */
const KEY = 'cf_app_banner_closed_at';

function platform() {
    const ua = navigator.userAgent || '';
    if (/Android/i.test(ua)) return 'android';
    // iPadOS reports itself as a Mac: a Mac with a touch screen is an iPad.
    if (/iPhone|iPad|iPod/i.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1)) return 'ios';
    return null;
}

function installed() {
    return Boolean(window.Capacitor?.isNativePlatform?.())
        || window.matchMedia?.('(display-mode: standalone)').matches
        || window.navigator.standalone === true;
}

function closedRecently(days) {
    try {
        const at = Number(localStorage.getItem(KEY) || 0);
        return at > 0 && Date.now() - at < days * 86400000;
    } catch {
        return false;
    }
}

function init() {
    const el = document.querySelector('[data-app-banner]');
    if (!el || el.dataset.ready) return;
    el.dataset.ready = '1';

    const kind = platform();
    const days = Number(el.dataset.hideDays || 14);
    if (!kind || installed() || closedRecently(days)) return;

    el.querySelectorAll(`[data-app-banner-${kind}]`).forEach((n) => { n.hidden = false; });
    el.hidden = false;

    el.querySelector('[data-app-banner-close]')?.addEventListener('click', () => {
        el.hidden = true;
        try { localStorage.setItem(KEY, String(Date.now())); } catch { /* storage blocked: hide for this page only */ }
    });
}

document.addEventListener('DOMContentLoaded', init);
document.addEventListener('livewire:navigated', init);
