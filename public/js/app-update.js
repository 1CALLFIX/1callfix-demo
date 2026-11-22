/*
 * App Upgrade (owner 2026-11-22). Inside our Android apps only: when the phone's build is older than the newest one
 * Admin → Settings → App Upgrade names for this app, show "Update" (ask: with a "Later" button that stays quiet for a
 * day; must: a full screen with only Update). Reads the build with the Capacitor App plugin; when it cannot (a browser,
 * an old shell, any error) it does nothing, so no one is ever blocked by a failed check. Inline styles: the customer,
 * partner and rider layouts do not share one stylesheet.
 */
(function () {
    var tag = document.currentScript;
    if (!tag || window.__cfAppUpdate) return;
    window.__cfAppUpdate = true;

    var config, text;
    try {
        config = JSON.parse(tag.getAttribute('data-config') || '{}');
        text = JSON.parse(tag.getAttribute('data-text') || '{}');
    } catch (e) {
        return;
    }

    var ua = navigator.userAgent || '';
    if (ua.indexOf('1CallFixApp') === -1) return;
    var m = ua.match(/1CallFixApp\/(customer|rider|partner)\b/);
    var app = m ? m[1] : 'customer';
    var rule = config[app];
    if (!rule || !rule.latest || (rule.mode !== 'ask' && rule.mode !== 'must')) return;

    var plugin = window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.App;
    if (!plugin || typeof plugin.getInfo !== 'function') return;

    var laterKey = 'cf_update_later_' + app + '_' + rule.latest;

    function snoozed() {
        try {
            var until = parseInt(localStorage.getItem(laterKey) || '0', 10);
            return until > Date.now();
        } catch (e) {
            return false;
        }
    }

    function button(label, primary) {
        var b = document.createElement(primary ? 'a' : 'button');
        b.textContent = label;
        b.style.cssText = 'display:block;width:100%;box-sizing:border-box;min-height:48px;line-height:48px;border-radius:12px;'
            + 'font-size:16px;font-weight:700;text-align:center;text-decoration:none;border:0;margin-top:10px;cursor:pointer;'
            + (primary ? 'background:#2563eb;color:#fff;' : 'background:#f1f5f9;color:#334155;');
        return b;
    }

    function show(must) {
        if (document.getElementById('cf-app-update')) return;
        var wrap = document.createElement('div');
        wrap.id = 'cf-app-update';
        wrap.setAttribute('role', 'dialog');
        wrap.setAttribute('aria-modal', must ? 'true' : 'false');
        wrap.style.cssText = 'position:fixed;inset:0;z-index:2147483000;display:flex;align-items:' + (must ? 'center' : 'flex-end')
            + ';justify-content:center;padding:16px;background:rgba(15,23,42,' + (must ? '0.85' : '0.45') + ');font-family:system-ui,sans-serif;';

        var card = document.createElement('div');
        card.style.cssText = 'width:100%;max-width:420px;background:#fff;border-radius:20px;padding:22px 20px 18px;box-shadow:0 20px 40px rgba(0,0,0,.25);';

        var icon = document.createElement('div');
        icon.textContent = '⬆';
        icon.style.cssText = 'width:52px;height:52px;border-radius:16px;background:#dbeafe;color:#2563eb;font-size:26px;line-height:52px;text-align:center;margin:0 auto 12px;';

        var h = document.createElement('p');
        h.textContent = must ? text.must_title : text.ask_title;
        h.style.cssText = 'margin:0;font-size:19px;font-weight:800;color:#0f172a;text-align:center;';

        var p = document.createElement('p');
        p.textContent = text.body || '';
        p.style.cssText = 'margin:8px 0 6px;font-size:14px;color:#475569;text-align:center;line-height:1.45;';

        var update = button(text.update, true);
        // Same as the Rate & review link: Play Store is outside the app's own pages, so the app hands it to the phone.
        update.href = rule.store;

        card.appendChild(icon);
        card.appendChild(h);
        card.appendChild(p);
        card.appendChild(update);

        if (!must) {
            var later = button(text.later, false);
            later.type = 'button';
            later.addEventListener('click', function () {
                try { localStorage.setItem(laterKey, String(Date.now() + 24 * 3600 * 1000)); } catch (e) {}
                wrap.remove();
            });
            card.appendChild(later);
        }

        wrap.appendChild(card);
        document.body.appendChild(wrap);
    }

    function check() {
        if (rule.mode === 'ask' && snoozed()) return;
        Promise.resolve(plugin.getInfo()).then(function (info) {
            var build = parseInt(info && info.build, 10);
            if (!build || build >= rule.latest) return;
            show(rule.mode === 'must');
        }).catch(function () {});
    }

    check();
    // Livewire page changes swap the body: show it again on the next page for "must" (and "ask" until Later).
    document.addEventListener('livewire:navigated', check);
})();
