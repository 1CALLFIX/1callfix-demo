/*
 * Social posts, "Share on …" buttons (owner 2026-11-21). Plain file, no build step.
 *  - Phone: the caption is copied (Instagram does not take text from the share sheet), then the phone's share sheet
 *    opens with the picture + caption, so the post goes to Instagram / Facebook / WhatsApp / Google in one tap.
 *  - Computer: the caption is copied, the picture is downloaded, and the platform's own post page opens in a new tab.
 * After posting, the person taps "Done ✓" on the card (a Livewire button), which records it.
 */
(function () {
    if (window.__cfSocialShare) return;
    window.__cfSocialShare = true;

    function note(button, text) {
        var old = button.getAttribute('data-label') || button.textContent;
        button.setAttribute('data-label', old);
        button.textContent = text;
        setTimeout(function () { button.textContent = old; }, 4000);
    }

    function copy(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text).catch(function () { return fallbackCopy(text); });
        }
        return Promise.resolve(fallbackCopy(text));
    }

    function fallbackCopy(text) {
        var area = document.createElement('textarea');
        area.value = text;
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        try { document.execCommand('copy'); } catch (e) { /* ignore */ }
        area.remove();
    }

    function picture(url) {
        if (!url) return Promise.resolve(null);
        return fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.blob() : null; })
            .then(function (blob) {
                if (!blob) return null;
                var ext = ((blob.type || 'image/jpeg').split('/')[1] || 'jpg').replace('jpeg', 'jpg');
                return new File([blob], '1callfix-post.' + ext, { type: blob.type || 'image/jpeg' });
            })
            .catch(function () { return null; });
    }

    var isPhone = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;

    document.addEventListener('click', function (event) {
        var button = event.target.closest && event.target.closest('[data-share]');
        if (!button) return;
        event.preventDefault();

        var text = button.getAttribute('data-share-text') || '';
        var image = button.getAttribute('data-share-image') || '';
        var open = button.getAttribute('data-share-open') || '';
        var done = button.getAttribute('data-share-done') || 'Shared ✓ — now tap Done ✓';

        // Inside our Android apps (2026-11-22): the phone's own share sheet through @capacitor/share. Words + link;
        // WhatsApp and Facebook show the page's picture from the link by themselves.
        var capShare = window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.Share;
        if (capShare && typeof capShare.share === 'function') {
            copy(text).then(function () {
                return capShare.share({ text: text, dialogTitle: 'Share' });
            }).then(function () { note(button, done); }).catch(function () {});
            return;
        }

        copy(text).then(function () { return picture(image); }).then(function (file) {
            var files = file ? [file] : [];
            if (isPhone && navigator.share) {
                var data = files.length && navigator.canShare && navigator.canShare({ files: files }) ? { files: files, text: text } : { text: text };
                return navigator.share(data).then(function () {
                    note(button, done);
                }).catch(function (err) {
                    if (!err || err.name !== 'AbortError') note(button, 'Caption copied — paste it in the app');
                });
            }
            if (file) {
                var link = document.createElement('a');
                link.href = URL.createObjectURL(file);
                link.download = file.name;
                document.body.appendChild(link);
                link.click();
                link.remove();
            }
            if (open) window.open(open, '_blank', 'noopener');
            note(button, file ? 'Caption copied, picture saved — paste and attach' : 'Caption copied — paste it');
        });
    });
})();
