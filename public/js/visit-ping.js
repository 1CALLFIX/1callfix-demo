/*
 * Live Visitors (owner 2026-11-21). Tells the server "this page was opened" and, once a minute while the page is in
 * view, "still here", so Admin → Live Visitors can show who is online and what they are looking at.
 * The visitor is a random id kept in this browser (nothing personal). Plain file, no build step; loaded once
 * (data-navigate-once) and also counts pages opened through Livewire's in-page navigation.
 */
(function () {
    if (window.__cfVisitPing) return;
    window.__cfVisitPing = true;

    var script = document.currentScript;
    var url = script && script.getAttribute('data-url');
    if (!url || !navigator.sendBeacon || !window.FormData) return;
    // Seconds between 'still here' pings (config visitors.beat_seconds); slower = lighter on the server.
    var beatMs = Math.max(30, parseInt(script.getAttribute('data-beat'), 10) || 60) * 1000;

    function newId() {
        var bytes = new Uint8Array(16);
        (window.crypto || window.msCrypto).getRandomValues(bytes);
        return Array.prototype.map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
    }

    function visitorId() {
        try {
            var id = localStorage.getItem('cf_vid');
            if (!id || !/^[a-f0-9]{32}$/.test(id)) {
                id = newId();
                localStorage.setItem('cf_vid', id);
            }
            return id;
        } catch (e) {
            return window.__cfVid || (window.__cfVid = newId());
        }
    }

    function token() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    var firstView = true;
    function referrerHost() {
        if (!firstView || !document.referrer) return '';
        try {
            var host = new URL(document.referrer).hostname;
            return host === location.hostname ? '' : host;
        } catch (e) {
            return '';
        }
    }

    function send(kind) {
        try {
            var data = new FormData();
            data.append('_token', token());
            data.append('k', kind);
            data.append('vid', visitorId());
            data.append('p', location.pathname);
            data.append('t', (document.title || '').slice(0, 200));
            if (kind === 'view') {
                data.append('r', referrerHost());
                firstView = false;
            }
            navigator.sendBeacon(url, data);
        } catch (e) { /* never disturb the page */ }
    }

    var lastPath = '';
    var lastAt = 0;
    function view() {
        var now = Date.now();
        if (location.pathname === lastPath && now - lastAt < 3000) return; // load + livewire:navigated for the same page
        lastPath = location.pathname;
        lastAt = now;
        send('view');
    }

    document.addEventListener('livewire:navigated', view);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', view);
    } else {
        view();
    }

    setInterval(function () {
        if (document.visibilityState === 'visible') send('beat');
    }, beatMs);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') send('beat');
    });
})();
