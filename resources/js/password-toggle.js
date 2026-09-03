/**
 * Show/hide toggle for <x-ui.password-input>. One delegated listener for the
 * whole site — no per-form wiring, and it keeps working across Livewire DOM
 * morphs because the listener lives on `document`, not on the buttons.
 *
 * Markup contract (components/ui/password-input.blade.php):
 *   [data-password-field]  wrapper
 *     input                the password field
 *     [data-password-toggle]  the button, with [data-icon-show] / [data-icon-hide] inside
 */

function setRevealed(wrap, revealed) {
    const input = wrap.querySelector('input');
    const btn = wrap.querySelector('[data-password-toggle]');
    if (!input || !btn) return;

    input.type = revealed ? 'text' : 'password';
    btn.setAttribute('aria-pressed', String(revealed));
    btn.setAttribute('aria-label', revealed ? 'Hide password' : 'Show password');
    wrap.querySelector('[data-icon-show]')?.classList.toggle('hidden', revealed);
    wrap.querySelector('[data-icon-hide]')?.classList.toggle('hidden', !revealed);
}

document.addEventListener('click', (event) => {
    const btn = event.target.closest?.('[data-password-toggle]');
    if (!btn) return;
    event.preventDefault();
    const wrap = btn.closest('[data-password-field]');
    if (!wrap) return;
    const currentlyRevealed = wrap.querySelector('input')?.type === 'text';
    setRevealed(wrap, !currentlyRevealed);
});

/**
 * A Livewire re-render (e.g. a failed submit re-rendering the form) morphs
 * the input back to type="password". Re-assert any field the user had
 * chosen to reveal so the toggle doesn't silently snap shut under them.
 */
function reassertRevealedFields() {
    document
        .querySelectorAll('[data-password-field] [data-password-toggle][aria-pressed="true"]')
        .forEach((btn) => {
            const wrap = btn.closest('[data-password-field]');
            if (wrap && wrap.querySelector('input')?.type === 'password') {
                setRevealed(wrap, true);
            }
        });
}

document.addEventListener('livewire:init', () => {
    window.Livewire?.hook?.('morphed', reassertRevealedFields);
    window.Livewire?.hook?.('morph.updated', reassertRevealedFields);
});
document.addEventListener('livewire:navigated', reassertRevealedFields);
