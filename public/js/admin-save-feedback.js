/*
 * Save confirmation beside the button (owner 2026-11-07): on long admin screens the "Saved" message sat at the top of
 * the page, out of sight of the Save button at the bottom. This shows the screen's own result right next to the button
 * that was pressed, on EVERY admin screen, with no change to each screen:
 *   - green  = the screen's own message ($flashMessage, or its session "message" box marked data-flash), e.g. "Saved."
 *   - red    = the save was refused: fields have errors ("Not saved: fix the fields marked in red"), and the first
 *              field in error is scrolled into view.
 * When the button itself is off screen, the same note also pops up at the bottom of the window.
 * It only reacts to save-like actions (a form submit, or a button whose wire:click is save/update/store/create/...),
 * and never invents a result: no message from the screen = nothing shown.
 * Plain script (not Vite): the admin layout loads it after @livewireScripts.
 */
(function () {
    // Once per tab: wire:navigate page changes must not add a second set of listeners (double notes).
    if (window.__cfSaveFeedback) { return; }
    window.__cfSaveFeedback = true;
    var SAVE_LIKE = /^(save|update|store|create|submit|apply|publish|approve|confirm|send|record|upload|import|generate)(?![a-z]*Row)/i;
    var last = { el: null, at: 0 };

    function remember(el) {
        if (el) { last = { el: el, at: Date.now() }; }
    }
    document.addEventListener('click', function (e) {
        remember(e.target.closest && e.target.closest('button, [type=submit], a[wire\\:click]'));
    }, true);
    document.addEventListener('submit', function (e) {
        var b = e.submitter || (e.target.querySelector && e.target.querySelector('[type=submit]'));
        remember(b);
    }, true);

    function isSaveLike(el, calls) {
        if (!el) { return false; }
        var form = el.closest('form');
        if ((el.type === 'submit' || el.getAttribute('type') === 'submit') && form && form.hasAttribute('wire:submit')) { return true; }
        var wc = el.getAttribute('wire:click') || '';
        var method = wc.replace(/^\$/, '').split(/[(\s]/)[0];
        if (method && SAVE_LIKE.test(method)) { return true; }
        return (calls || []).some(function (c) { return SAVE_LIKE.test(c.method || ''); }) && el.type === 'submit';
    }

    function sessionFlash(html) {
        // Screens that flash session('message') render it in a box marked data-flash; read it from THIS save's response
        // only (so an old message still on screen is never shown as a new result).
        if (!html) { return ''; }
        var box = new DOMParser().parseFromString(html, 'text/html').querySelector('[data-flash]');
        return box ? box.textContent.trim() : '';
    }

    function note(text, ok) {
        var n = document.createElement('span');
        n.setAttribute('role', ok ? 'status' : 'alert');
        n.setAttribute('data-save-note', '');
        n.textContent = (ok ? '✓ ' : '✕ ') + text;
        n.style.cssText = 'display:inline-flex;align-items:center;margin:0 .5rem;padding:.35rem .75rem;border-radius:9999px;'
            + 'font-size:.8125rem;font-weight:600;line-height:1.2;vertical-align:middle;transition:opacity .4s;max-width:28rem;'
            + (ok ? 'background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;' : 'background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;');
        return n;
    }

    function show(btn, text, ok) {
        document.querySelectorAll('[data-save-note]').forEach(function (x) { x.remove(); });
        var beside = note(text, ok);
        if (btn && btn.isConnected) { btn.insertAdjacentElement('afterend', beside); }

        var r = btn && btn.isConnected ? btn.getBoundingClientRect() : null;
        var offScreen = !r || r.bottom < 0 || r.top > window.innerHeight;
        var pop = null;
        if (offScreen) {
            pop = note(text, ok);
            pop.style.position = 'fixed';
            pop.style.bottom = '1.25rem';
            pop.style.right = '1.25rem';
            pop.style.zIndex = '9999';
            pop.style.boxShadow = '0 10px 25px -10px rgba(15,23,42,.35)';
            pop.style.padding = '.6rem 1rem';
            document.body.appendChild(pop);
        }
        setTimeout(function () {
            [beside, pop].forEach(function (x) { if (x) { x.style.opacity = '0'; setTimeout(function () { x.remove(); }, 450); } });
        }, ok ? 6000 : 9000);
    }

    function register() {
        if (!window.Livewire || register.done) { return; }
        register.done = true;
        window.Livewire.hook('commit', function (ctx) {
            var btn = Date.now() - last.at < 4000 ? last.el : null;
            if (!btn || !ctx.component.el.contains(btn) || !isSaveLike(btn, ctx.commit && ctx.commit.calls)) { return; }
            ctx.succeed(function (res) {
                setTimeout(function () {
                    var s = {};
                    try { s = typeof res.snapshot === 'string' ? JSON.parse(res.snapshot) : (res.snapshot || {}); } catch (e) { s = {}; }
                    var errors = (s.memo && s.memo.errors) || {};
                    var errorKeys = Array.isArray(errors) ? [] : Object.keys(errors);
                    if (errorKeys.length) {
                        show(btn, 'Not saved: fix the fields marked in red', false);
                        var first = ctx.component.el.querySelector('.border-red-500, .text-red-600, [aria-invalid=true]');
                        if (first) { first.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
                        return;
                    }
                    var data = s.data || {};
                    var msg = typeof data.flashMessage === 'string' ? data.flashMessage : '';
                    if (!msg) { msg = sessionFlash(res.effects && res.effects.html); }
                    if (msg) { show(btn, msg, true); }
                }, 30);
            });
        });
    }

    if (window.Livewire) { register(); }
    document.addEventListener('livewire:init', register);
})();
