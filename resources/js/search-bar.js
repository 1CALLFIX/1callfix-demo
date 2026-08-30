/**
 * Keyboard cursor for the customer search suggestion dropdown.
 *
 * The server (App\Livewire\Customer\SearchBar) owns the list: what matches,
 * how it is grouped, when it opens. This file owns only the bit that has to
 * run in the browser — moving a highlight through the rendered options with
 * the arrow keys, opening one with Enter, and telling the component to open
 * on an empty focus so the default list can show.
 *
 * Progressive enhancement, like carousel.js: with this switched off every
 * option is still a plain reachable <a>, and typing still narrows the list
 * over the wire. No dependencies — this codebase ships no Alpine.
 *
 * ── Markup contract (resources/views/livewire/customer/search-bar.blade.php)
 *   [data-search-bar]      the Livewire root <form> (carries wire:id)
 *   [data-search-input]    the role="combobox" input
 *   [data-search-option]   each role="option" <a>, with a unique id
 *
 * The option ids are positional (…-opt-0, …-opt-1) and the elements sit in
 * DOM order, so "next option" is just the next node — no index bookkeeping
 * that could drift from what is on screen.
 */

function initSearchBar(root) {
    if (root.dataset.searchReady === 'true') {
        return;
    }
    root.dataset.searchReady = 'true';

    const input = root.querySelector('[data-search-input]');
    if (!input) {
        return;
    }

    let focusRequested = false;

    const options = () => Array.from(root.querySelectorAll('[data-search-option]'));

    const activeOption = () => root.querySelector('[data-search-option][data-active="true"]');

    const clearActive = () => {
        const current = activeOption();
        if (current) {
            current.removeAttribute('data-active');
            current.setAttribute('aria-selected', 'false');
        }
        input.removeAttribute('aria-activedescendant');
    };

    const setActive = (option) => {
        if (!option) {
            clearActive();
            return;
        }
        clearActive();
        option.dataset.active = 'true';
        option.setAttribute('aria-selected', 'true');
        input.setAttribute('aria-activedescendant', option.id);
        // block:'nearest' keeps the panel from jumping when the option is
        // already fully visible.
        option.scrollIntoView({ block: 'nearest' });
    };

    const move = (delta) => {
        const opts = options();
        if (opts.length === 0) {
            return;
        }
        const current = activeOption();
        let nextIndex;
        if (!current) {
            nextIndex = delta > 0 ? 0 : opts.length - 1;
        } else {
            const at = opts.indexOf(current);
            nextIndex = (at + delta + opts.length) % opts.length;
        }
        setActive(opts[nextIndex]);
    };

    // An empty field should open onto the default list. Ask the component
    // once per focus rather than on every focusin.
    input.addEventListener('focus', () => {
        if (focusRequested || input.value.trim() !== '') {
            return;
        }
        focusRequested = true;
        const id = root.getAttribute('wire:id');
        if (id && window.Livewire) {
            window.Livewire.find(id)?.call('focusField');
        }
    });

    root.addEventListener('focusout', (event) => {
        if (!root.contains(event.relatedTarget)) {
            focusRequested = false;
            clearActive();
        }
    });

    input.addEventListener('keydown', (event) => {
        switch (event.key) {
            case 'ArrowDown':
                event.preventDefault();
                move(1);
                break;
            case 'ArrowUp':
                event.preventDefault();
                move(-1);
                break;
            case 'Home':
                if (options().length) {
                    event.preventDefault();
                    setActive(options()[0]);
                }
                break;
            case 'End':
                if (options().length) {
                    event.preventDefault();
                    setActive(options()[options().length - 1]);
                }
                break;
            case 'Enter': {
                const option = activeOption();
                if (option) {
                    // A real highlighted choice: open it instead of
                    // submitting the form to the full search screen.
                    event.preventDefault();
                    option.click();
                }
                break;
            }
            case 'Escape':
                // wire:keydown.escape on the input already closes the list
                // server-side; just drop the visual cursor here.
                clearActive();
                break;
            default:
                break;
        }
    });

    // The list is about to be replaced by the debounced round trip — a
    // highlight pointing at a row that is changing is worse than none.
    input.addEventListener('input', clearActive);

    // Mouse and keyboard share one notion of "active" so they can't fight.
    root.addEventListener('pointerover', (event) => {
        const option = event.target.closest('[data-search-option]');
        if (option && root.contains(option)) {
            setActive(option);
        }
    });
}

/**
 * The mobile / mid-width header search drawer: a button that reveals the
 * same compact search component beneath the bar. Kept here because it is the
 * other half of "the header search" and shares no state with the page.
 */
function initSearchDrawer(toggle) {
    if (toggle.dataset.searchToggleReady === 'true') {
        return;
    }
    toggle.dataset.searchToggleReady = 'true';

    const drawer = document.getElementById(toggle.getAttribute('aria-controls'))
        || document.querySelector('[data-search-drawer]');
    if (!drawer) {
        return;
    }

    const close = () => {
        drawer.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
    };

    const open = () => {
        drawer.hidden = false;
        toggle.setAttribute('aria-expanded', 'true');
        drawer.querySelector('[data-search-input]')?.focus();
    };

    toggle.addEventListener('click', () => (drawer.hidden ? open() : close()));

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !drawer.hidden) {
            close();
            toggle.focus();
        }
    });

    document.addEventListener('click', (event) => {
        if (drawer.hidden) {
            return;
        }
        if (!drawer.contains(event.target) && !toggle.contains(event.target)) {
            close();
        }
    });
}

function initAll(scope = document) {
    scope.querySelectorAll('[data-search-bar]').forEach(initSearchBar);
    scope.querySelectorAll('[data-search-toggle]').forEach(initSearchDrawer);
}

document.addEventListener('DOMContentLoaded', () => initAll());
document.addEventListener('livewire:navigated', () => initAll());
document.addEventListener('livewire:init', () => {
    // A full component swap drops data-search-ready with the old DOM; rebind
    // whatever came back. Existing nodes keep their listeners and are skipped
    // by the guard.
    window.Livewire?.hook?.('morphed', ({ el }) => initAll(el));
});
