/**
 * Banner carousel behaviour for the customer web app (Phase C).
 *
 * ── Progressive enhancement, deliberately ─────────────────────────────────
 * The markup this drives (resources/views/components/customer/banner-carousel
 * .blade.php) is already a usable carousel with this file switched off: the
 * track is a scroll-snap row, so it swipes natively on touch, scrolls with
 * the keyboard, and shows every slide. Everything here is additive — arrows,
 * dot pagination, auto-advance, and the announcements that go with them. A
 * JS failure degrades to "a row you swipe", never to a single frozen slide.
 *
 * That is also why position is read from `scrollLeft` rather than tracked in
 * a variable: the browser's own scrolling (a swipe, a shift-wheel, a
 * keyboard scroll) stays the source of truth, so the dots cannot drift out
 * of sync with what is actually on screen.
 *
 * ── Motion ────────────────────────────────────────────────────────────────
 * Auto-advance never starts when the viewer has asked for reduced motion
 * (WCAG 2.1 AA 2.3.3), and the media query is watched live so toggling the
 * OS setting takes effect without a reload. It also pauses on hover, on
 * keyboard focus anywhere inside the carousel, and whenever the tab is
 * hidden — an animation running against a user who is reading, or in a tab
 * nobody is looking at, is just wasted work.
 *
 * A visible play/pause control is present whenever auto-advance is on
 * (WCAG 2.1 AA 2.2.2), and pressing it is sticky: the carousel stays paused
 * until the viewer restarts it.
 *
 * No dependencies. This codebase ships no Alpine and no carousel library,
 * and adding one for a component this size would cost more bytes on the
 * homepage's critical path than the whole feature.
 */

// Fallback only. Each call site sets its own cadence via the component's
// `:interval` prop (config/banners.php) → `data-carousel-interval`; this
// value is used only when a carousel is rendered without one.
const AUTOPLAY_MS = 6000;

function initCarousel(root) {
    if (root.dataset.carouselReady === 'true') {
        return;
    }
    root.dataset.carouselReady = 'true';

    const track = root.querySelector('[data-carousel-track]');
    const slides = Array.from(root.querySelectorAll('[data-carousel-slide]'));
    const dots = Array.from(root.querySelectorAll('[data-carousel-dot]'));
    const prev = root.querySelector('[data-carousel-prev]');
    const next = root.querySelector('[data-carousel-next]');
    const toggle = root.querySelector('[data-carousel-toggle]');
    const status = root.querySelector('[data-carousel-status]');

    if (!track || slides.length === 0) {
        return;
    }

    // Controls are hidden in the markup and revealed here, so a no-JS
    // viewer is never shown arrows that would do nothing.
    root.querySelectorAll('[data-carousel-enhanced]').forEach((el) => el.removeAttribute('hidden'));

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const wantsAutoplay = root.dataset.carouselAutoplay === 'true' && slides.length > 1;

    // Per-call-site cadence (hero rotates slower than the mid strip). A
    // missing, non-numeric or non-positive value falls back to the default.
    const parsedInterval = Number.parseInt(root.dataset.carouselInterval, 10);
    const autoplayMs = Number.isFinite(parsedInterval) && parsedInterval > 0 ? parsedInterval : AUTOPLAY_MS;

    let pausedByUser = false;
    let timer = null;

    const currentIndex = () => {
        // Nearest slide to the current scroll position — correct whether we
        // scrolled programmatically or the viewer swiped.
        const position = track.scrollLeft;
        let best = 0;
        let bestDistance = Infinity;

        slides.forEach((slide, index) => {
            const distance = Math.abs(slide.offsetLeft - track.offsetLeft - position);
            if (distance < bestDistance) {
                bestDistance = distance;
                best = index;
            }
        });

        return best;
    };

    let fallbackTimer = null;

    /**
     * Scroll to a slide, wrapping at both ends.
     *
     * ── Why there is a fallback ───────────────────────────────────────────
     * Smooth scrolling is not guaranteed to be available. In the automated
     * Chrome used to test this, `scrollTo({behavior: 'smooth'})` never moved
     * anything at all — not this carousel, not a freshly-created plain
     * overflow container, not even `window.scrollTo`; the same calls with
     * `behavior: 'auto'` landed every time. Whatever disables it (automation,
     * an enterprise policy, an extension), the failure mode is severe and
     * silent: swiping still works perfectly while every arrow, dot and arrow
     * key does nothing whatsoever.
     *
     * So the animation is requested, and if the track has not begun moving
     * shortly afterwards it is jumped into place instead. A carousel that
     * animates where it can and jumps where it cannot is strictly better than
     * one that is dead in an environment nobody thought to check.
     *
     * Reduced-motion users take the `auto` path outright — no request, no
     * wait (WCAG 2.1 AA 2.3.3).
     */
    const goTo = (index, smooth = true) => {
        const wrapped = (index + slides.length) % slides.length;
        const left = slides[wrapped].offsetLeft - track.offsetLeft;

        window.clearTimeout(fallbackTimer);

        if (!smooth || reduceMotion.matches) {
            track.scrollTo({ left, behavior: 'auto' });
        } else {
            const from = track.scrollLeft;
            track.scrollTo({ left, behavior: 'smooth' });

            fallbackTimer = window.setTimeout(() => {
                if (track.scrollLeft === from && from !== left) {
                    track.scrollTo({ left, behavior: 'auto' });
                }
            }, 250);
        }

        // Paint the controls for where we are going, not where we were.
        syncControls(wrapped);
    };

    /**
     * Paint the controls for whichever slide is current.
     *
     * `index` is passed explicitly by goTo(), because at the moment a scroll
     * is *initiated* the container has not moved yet — deriving the index from
     * `scrollLeft` there would just re-report the slide being left behind.
     * Relying on the scroll event alone to catch up was not enough either:
     * programmatic scrolls did not reliably emit one in browser testing, and
     * the dots sat on slide 1 while the track was visibly on slide 2.
     * Omitting it (the swipe case) derives from the real scroll position,
     * which is what makes a finger-swipe update the dots correctly.
     */
    const syncControls = (index = currentIndex()) => {
        dots.forEach((dot, i) => {
            const isCurrent = i === index;
            dot.setAttribute('aria-current', isCurrent ? 'true' : 'false');

            // The colour goes on the small inner pip, never on the button
            // itself: the button is deliberately a 44px hit area (WCAG 2.5.5)
            // and painting THAT would draw a tall rectangle instead of a dot.
            const pip = dot.querySelector('[data-carousel-pip]');
            if (pip) {
                pip.classList.toggle('bg-slate-900', isCurrent);
                pip.classList.toggle('bg-slate-300', !isCurrent);
            }
        });

        // Announced only while auto-advance is stopped. A live region that
        // fires on every automatic rotation talks over everything else a
        // screen reader is trying to say (WCAG 2.1 AA 4.1.3).
        if (status && (!wantsAutoplay || pausedByUser || reduceMotion.matches)) {
            status.textContent = `Slide ${index + 1} of ${slides.length}`;
        }
    };

    const stop = () => {
        if (timer !== null) {
            window.clearInterval(timer);
            timer = null;
        }
    };

    const start = () => {
        stop();
        if (!wantsAutoplay || pausedByUser || reduceMotion.matches || document.hidden) {
            return;
        }
        timer = window.setInterval(() => goTo(currentIndex() + 1), autoplayMs);
    };

    /**
     * The control reflects the VIEWER'S choice, not whether a timer happens
     * to be ticking this instant.
     *
     * Those are different things: rotation also stops while the pointer is
     * over the carousel, while focus is inside it, and while the tab is
     * hidden — all transient, none of them the viewer pressing pause. Labelling
     * from the timer made the button read "Start banner rotation" on a page
     * whose rotation was merely paused for hover, so pressing it appeared to
     * do nothing (it set the sticky pause on something already stopped).
     * Caught in browser testing, where the tab is hidden and the timer is
     * therefore never running.
     */
    const setToggleState = () => {
        if (!toggle) {
            return;
        }
        toggle.setAttribute('aria-pressed', pausedByUser ? 'true' : 'false');
        toggle.querySelector('[data-carousel-toggle-label]').textContent = pausedByUser
            ? 'Start banner rotation'
            : 'Pause banner rotation';
        toggle.dataset.state = pausedByUser ? 'paused' : 'playing';
    };

    prev?.addEventListener('click', () => {
        goTo(currentIndex() - 1);
    });

    next?.addEventListener('click', () => {
        goTo(currentIndex() + 1);
    });

    dots.forEach((dot, index) => {
        dot.addEventListener('click', () => goTo(index));
    });

    toggle?.addEventListener('click', () => {
        pausedByUser = !pausedByUser;
        pausedByUser ? stop() : start();
        setToggleState();
        syncControls();
    });

    // Left/Right anywhere inside the carousel, matching the APG carousel
    // pattern. Ignored while a text field has focus so typing is unaffected.
    root.addEventListener('keydown', (event) => {
        if (event.target.closest('input, textarea, select')) {
            return;
        }
        if (event.key === 'ArrowLeft') {
            event.preventDefault();
            goTo(currentIndex() - 1);
        } else if (event.key === 'ArrowRight') {
            event.preventDefault();
            goTo(currentIndex() + 1);
        }
    });

    // Pause while the viewer is interacting or reading.
    root.addEventListener('mouseenter', stop);
    root.addEventListener('mouseleave', start);
    root.addEventListener('focusin', stop);
    root.addEventListener('focusout', (event) => {
        if (!root.contains(event.relatedTarget)) {
            start();
        }
    });

    document.addEventListener('visibilitychange', () => (document.hidden ? stop() : start()));

    let scrollTick = null;
    track.addEventListener('scroll', () => {
        window.clearTimeout(scrollTick);
        scrollTick = window.setTimeout(syncControls, 80);
    }, { passive: true });

    reduceMotion.addEventListener('change', () => {
        start();
        setToggleState();
        syncControls();
    });

    syncControls();
    start();
    setToggleState();
}

function initAll(scope = document) {
    scope.querySelectorAll('[data-carousel]').forEach(initCarousel);
}

document.addEventListener('DOMContentLoaded', () => initAll());

/**
 * Livewire swaps DOM in place on every update, which can replace a carousel
 * with a fresh, uninitialised copy. `data-carousel-ready` makes re-running
 * initialisation idempotent, so this hook only ever wires up what is
 * genuinely new. Guarded because this bundle also loads on pages that render
 * no Livewire component at all.
 */
document.addEventListener('livewire:navigated', () => initAll());
document.addEventListener('livewire:init', () => {
    window.Livewire?.hook?.('morphed', ({ el }) => initAll(el));
});
