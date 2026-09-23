(() => {
    'use strict';

    const overlay = document.querySelector('[data-ops-shortcuts]');
    if (! overlay) {
        return;
    }

    const contracts = window.PlaneOpsContracts;
    const panel = overlay.querySelector('[data-ops-shortcuts-panel]');
    const chordWindowMs = 1500;

    /**
     * The overlay is the source of truth for what `g` can reach: every row it
     * lists carries its own key and server-rendered URL, so the script never
     * builds a route and the help text cannot promise a jump that does nothing.
     *
     * @type {Record<string, string>}
     */
    const targets = {};
    overlay.querySelectorAll('[data-ops-shortcuts-go]').forEach((row) => {
        if (! (row instanceof HTMLElement)) {
            return;
        }

        const key = (row.dataset.opsShortcutsGo || '').toLowerCase();
        const url = row.dataset.opsShortcutsUrl || '';
        if (key !== '' && url !== '') {
            targets[key] = url;
        }
    });

    /** @type {HTMLElement|null} */
    let previousFocus = null;
    let chordTimer = 0;
    let chordArmed = false;

    const isOpen = () => ! overlay.hidden;

    /** A confirm dialog owns the keyboard while it is up; never race its focus trap. */
    const confirmOpen = () => contracts.confirmOpen(document);

    /**
     * @param {EventTarget|null} node
     */
    const isTyping = (node) => contracts.isTyping(node);

    const endChord = () => {
        window.clearTimeout(chordTimer);
        chordTimer = 0;
        chordArmed = false;
    };

    const startChord = () => {
        chordArmed = true;
        window.clearTimeout(chordTimer);
        chordTimer = window.setTimeout(endChord, chordWindowMs);
    };

    const open = () => {
        if (isOpen()) {
            return;
        }

        previousFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        overlay.hidden = false;
        document.body.style.overflow = 'hidden';
        panel?.focus();
    };

    const close = () => {
        if (! isOpen()) {
            return;
        }

        overlay.hidden = true;
        document.body.style.overflow = '';

        if (previousFocus instanceof HTMLElement) {
            previousFocus.focus();
        }
        previousFocus = null;
    };

    /**
     * The search box of whichever list is on screen. Pages without a toolbar
     * (site detail, settings) leave the keystroke to the browser.
     */
    const focusListSearch = () => {
        const input = document.querySelector('[data-ops-list-toolbar] input[name="q"]');
        if (! (input instanceof HTMLInputElement)) {
            return false;
        }

        input.focus();
        input.select();

        return true;
    };

    /**
     * Escape in a list search box: clear a filled box (the toolbar re-queries on
     * `input`), or step out of an empty one so the bare-key shortcuts work again.
     *
     * @param {EventTarget|null} node
     */
    const escapeListSearch = (node) => {
        if (! (node instanceof HTMLInputElement) || node.name !== 'q' || ! node.closest('[data-ops-list-toolbar]')) {
            return false;
        }

        if (contracts.searchEscapeAction(node.value) === 'clear') {
            node.value = '';
            node.dispatchEvent(new Event('input', { bubbles: true }));
        } else {
            node.blur();
        }

        return true;
    };

    /**
     * `f` flips the filter panel of the list on screen, when that list has one.
     */
    const toggleListFilters = () => {
        const toggle = document.querySelector('[data-ops-shortcut-filters]');
        if (! (toggle instanceof HTMLElement) || toggle.closest('[hidden]') || toggle.hasAttribute('disabled')) {
            return false;
        }

        toggle.click();
        toggle.focus();

        return true;
    };

    overlay.querySelectorAll('[data-ops-shortcuts-close]').forEach((trigger) => {
        trigger.addEventListener('click', close);
    });

    // The trigger is rendered hidden so that a no-JS panel never offers a dead button.
    document.querySelectorAll('[data-ops-shortcuts-open]').forEach((trigger) => {
        if (trigger instanceof HTMLElement) {
            trigger.hidden = false;
            trigger.addEventListener('click', (event) => {
                event.preventDefault();
                open();
            });
        }
    });

    overlay.addEventListener('keydown', (event) => {
        if (event.key !== 'Tab' || ! panel) {
            return;
        }

        const focusable = panel.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
        if (focusable.length === 0) {
            return;
        }

        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (! event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.defaultPrevented) {
            return;
        }

        if (isOpen()) {
            if (event.key === 'Escape') {
                event.preventDefault();
                close();
            }

            return;
        }

        // A shortcut is a bare keystroke. Ctrl/Cmd/Alt combinations belong to the browser.
        if (event.ctrlKey || event.metaKey || event.altKey) {
            return;
        }

        if (confirmOpen()) {
            return;
        }

        if (event.key === 'Escape' && escapeListSearch(document.activeElement)) {
            event.preventDefault();

            return;
        }

        if (isTyping(document.activeElement)) {
            return;
        }

        const key = event.key.toLowerCase();

        if (chordArmed) {
            const target = targets[key];
            endChord();

            if (target) {
                event.preventDefault();
                window.location.assign(target);
            }

            return;
        }

        if (event.key === '/') {
            if (focusListSearch()) {
                event.preventDefault();
            }

            return;
        }

        if (event.key === '?') {
            event.preventDefault();
            open();

            return;
        }

        if (key === 'f') {
            if (toggleListFilters()) {
                event.preventDefault();
            }

            return;
        }

        if (key === 'g') {
            startChord();
        }
    });

    window.PlaneShortcuts = { open, close };
})();
