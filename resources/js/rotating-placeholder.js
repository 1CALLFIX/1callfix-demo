/**
 * Rotates the search bar's `placeholder` attribute through a short list of
 * examples ("Search for 'AC service'", ...) — 1CF-HOMESCREEN-UX-001, part 2.
 *
 * Deliberately does NOT touch the input's accessible name: the <label> stays
 * "Search for a service" the whole time (see search-bar.blade.php) and this
 * only ever rewrites the `placeholder` attribute, which is not read as the
 * field's name by screen readers once a proper <label> exists.
 *
 * Wires every `[data-search-input]` on the page — the header's compact box
 * and the mobile row both render the same SearchBar component (see
 * resources/views/components/customer/header.blade.php), so both rotate
 * independently off their own `data-placeholder-examples` JSON attribute.
 */
(function () {
    const ROTATE_MS = 3000;

    function parseExamples(input) {
        try {
            const raw = JSON.parse(input.dataset.placeholderExamples || '[]');
            return Array.isArray(raw) ? raw.filter((s) => typeof s === 'string' && s.trim() !== '') : [];
        } catch (e) {
            return [];
        }
    }

    function wire(input) {
        if (input.dataset.placeholderWired === '1') return;

        const examples = parseExamples(input);
        // Not marked "wired" yet on an empty list — a deep link that loads
        // with a non-empty search term (so the server intentionally sent no
        // examples, see SearchBar::render()) gets one more chance to wire up
        // once the field is cleared and a real re-render supplies them.
        if (examples.length === 0) return;

        input.dataset.placeholderWired = '1';

        const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        if (reduceMotion) {
            // One static placeholder, never rotated — spec requirement.
            input.placeholder = examples[0];
            return;
        }

        let index = 0;
        let timer = null;
        let paused = false;

        const tick = () => {
            if (paused || input.value !== '') return;
            index = (index + 1) % examples.length;
            input.placeholder = examples[index];
        };

        input.placeholder = examples[0];
        // Admin sets the pace (Admin > Growth > Search Box); fall back to the default.
        const ms = parseInt(input.dataset.rotateMs || '', 10);
        timer = setInterval(tick, ms >= 1000 && ms <= 10000 ? ms : ROTATE_MS);

        // Pause while focused or mid-typing; the field's own value (not just
        // "typing") already hides the placeholder in every browser, but
        // pausing rotation too avoids a jarring swap the instant focus
        // returns to an empty field.
        input.addEventListener('focus', () => { paused = true; });
        input.addEventListener('blur', () => { paused = input.value === '' ? false : paused; });
        input.addEventListener('input', () => { paused = true; });

        // Livewire re-renders can replace this exact node (wire:model
        // roundtrip on `term`) with a fresh one carrying the same data
        // attribute but not the wired flag or the running timer — clear the
        // old interval so it doesn't keep ticking against a detached node.
        const observer = new MutationObserver(() => {
            if (! document.body.contains(input)) {
                clearInterval(timer);
                observer.disconnect();
            }
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }

    function wireAll() {
        document.querySelectorAll('[data-search-input][data-placeholder-examples]').forEach(wire);
    }

    document.addEventListener('DOMContentLoaded', wireAll);
    document.addEventListener('livewire:navigated', wireAll);
    if (window.Livewire) {
        window.Livewire.hook('morphed', wireAll);
    } else {
        document.addEventListener('livewire:init', () => {
            window.Livewire.hook('morphed', wireAll);
        });
    }
})();
