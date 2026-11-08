/**
 * Pages the web server hands out without PHP (app/Support/LiteSpeed.php) carry an EMPTY security token, because one
 * saved copy goes to everyone. This puts the visitor's own token back, so every button, form and Livewire action
 * works exactly as on a page built for them:
 *  - from the cf_csrf cookie (any earlier visit in this browser session), else
 *  - from GET /_hello, which also starts the session and, on a city page (meta cf-hello), points the visitor at that city.
 * Does nothing on a normal page (its token is already filled in).
 */
const COOKIE = 'cf_csrf';

function readCookie(name) {
    const m = document.cookie.match(new RegExp('(?:^|;\\s*)' + name + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : '';
}

function fill(token) {
    document.querySelector('meta[name="csrf-token"]')?.setAttribute('content', token);
    document.querySelectorAll('[data-csrf]').forEach((el) => el.setAttribute('data-csrf', token));
    if (window.livewireScriptConfig) window.livewireScriptConfig.csrf = token;
    document.querySelectorAll('input[name="_token"]').forEach((input) => {
        if (!input.value) input.value = token;
    });
}

let asking = null;

function hello() {
    asking ??= fetch('/_hello?path=' + encodeURIComponent(location.pathname), {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    })
        .then((r) => (r.ok ? r.json() : {}))
        .then((j) => { if (j.token) fill(j.token); })
        .catch(() => {})
        .finally(() => { asking = null; });

    return asking;
}

function init() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (!meta || meta.getAttribute('content')) return;

    const saved = readCookie(COOKIE);
    const needsCity = document.querySelector('meta[name="cf-hello"]') && !readCookie('cf_zone');
    if (saved && !needsCity) {
        fill(saved);
        return;
    }
    if (saved) fill(saved); // usable at once; /_hello below only adds the city
    hello();
}

init();
document.addEventListener('livewire:navigated', init);
