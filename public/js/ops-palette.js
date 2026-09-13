(() => {
    'use strict';

    const root = document.querySelector('[data-ops-palette]');
    if (! root) {
        return;
    }

    const endpoint = root.dataset.opsPaletteUrl || '';
    const panel = root.querySelector('[data-ops-palette-panel]');
    const input = root.querySelector('[data-ops-palette-input]');
    const results = root.querySelector('[data-ops-palette-results]');
    const empty = root.querySelector('[data-ops-palette-empty]');

    if (! (input instanceof HTMLInputElement) || ! (results instanceof HTMLElement)) {
        return;
    }

    /** @type {HTMLElement|null} */
    let previousFocus = null;
    /** @type {number} */
    let debounceTimer = 0;
    /** @type {AbortController|null} */
    let inflight = null;
    let activeId = '';

    const contracts = window.PlaneOpsContracts;

    const isOpen = () => ! root.hidden;

    const confirmOpen = () => contracts.confirmOpen(document);

    const options = () => Array.from(results.querySelectorAll('[data-ops-palette-item]'))
        .filter((node) => node instanceof HTMLElement);

    const setActive = (id) => {
        activeId = id;
        options().forEach((option) => {
            const selected = option.id === id;
            option.classList.toggle('is-active', selected);
            option.setAttribute('aria-selected', selected ? 'true' : 'false');
        });
        input.setAttribute('aria-activedescendant', id);
        const current = options().find((option) => option.id === id);
        current?.scrollIntoView({ block: 'nearest' });
    };

    const moveActive = (delta) => {
        const items = options();
        if (items.length === 0) {
            return;
        }

        const index = items.findIndex((option) => option.id === activeId);
        const next = items[(index + delta + items.length) % items.length];
        setActive(next.id);
    };

    const followActive = () => {
        const current = options().find((option) => option.id === activeId);
        const href = current?.dataset.opsPaletteUrl || '';
        if (href !== '') {
            window.location.assign(href);
        }
    };

    /**
     * @param {Array<{key: string, label: string, items: Array<{id: string, label: string, hint: ?string, url: string}>}>} groups
     */
    const render = (groups) => {
        results.replaceChildren();
        let firstId = '';

        groups.forEach((group) => {
            if (! Array.isArray(group.items) || group.items.length === 0) {
                return;
            }

            const heading = document.createElement('p');
            heading.className = 'ops-palette-group';
            heading.textContent = group.label || '';
            results.append(heading);

            group.items.forEach((item) => {
                const option = document.createElement('a');
                option.className = 'ops-palette-item';
                option.id = String(item.id || '');
                option.href = String(item.url || '');
                option.dataset.opsPaletteItem = '1';
                option.dataset.opsPaletteUrl = String(item.url || '');
                option.setAttribute('role', 'option');
                option.setAttribute('aria-selected', 'false');

                const label = document.createElement('span');
                label.className = 'ops-palette-item-label';
                label.textContent = String(item.label || '');
                option.append(label);

                if (item.hint) {
                    const hint = document.createElement('span');
                    hint.className = 'ops-palette-item-hint';
                    hint.textContent = String(item.hint);
                    option.append(hint);
                }

                results.append(option);
                if (firstId === '') {
                    firstId = option.id;
                }
            });
        });

        const hasItems = options().length > 0;
        if (empty) {
            empty.hidden = hasItems;
        }
        results.hidden = ! hasItems;
        setActive(firstId);
    };

    const fetchResults = (q) => {
        if (endpoint === '') {
            return;
        }

        inflight?.abort();
        inflight = new AbortController();

        const url = new URL(endpoint, window.location.origin);
        url.searchParams.set('q', q);

        fetch(url.toString(), {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            signal: inflight.signal,
        }).then((response) => {
            if (! response.ok) {
                throw new Error('palette');
            }

            return response.json();
        }).then((payload) => {
            render(Array.isArray(payload?.groups) ? payload.groups : []);
        }).catch((error) => {
            if (error?.name === 'AbortError') {
                return;
            }

            render([]);
        });
    };

    const scheduleFetch = () => {
        window.clearTimeout(debounceTimer);
        debounceTimer = window.setTimeout(() => fetchResults(input.value.trim()), 200);
    };

    const open = () => {
        if (isOpen() || confirmOpen()) {
            return;
        }

        if (window.PlaneShortcuts && typeof window.PlaneShortcuts.close === 'function') {
            window.PlaneShortcuts.close();
        }

        previousFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        root.hidden = false;
        document.body.style.overflow = 'hidden';
        input.value = '';
        render([]);
        if (empty) {
            empty.hidden = true;
        }
        input.focus();
        fetchResults('');
    };

    const close = () => {
        if (! isOpen()) {
            return;
        }

        window.clearTimeout(debounceTimer);
        inflight?.abort();
        inflight = null;
        root.hidden = true;
        document.body.style.overflow = '';
        input.value = '';
        input.removeAttribute('aria-activedescendant');
        results.replaceChildren();
        if (empty) {
            empty.hidden = true;
        }

        if (previousFocus instanceof HTMLElement) {
            previousFocus.focus();
        }
        previousFocus = null;
    };

    root.querySelectorAll('[data-ops-palette-close]').forEach((trigger) => {
        trigger.addEventListener('click', close);
    });

    input.addEventListener('input', scheduleFetch);

    input.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            moveActive(1);

            return;
        }

        if (event.key === 'ArrowUp') {
            event.preventDefault();
            moveActive(-1);

            return;
        }

        if (event.key === 'Home') {
            event.preventDefault();
            const first = options()[0];
            if (first) {
                setActive(first.id);
            }

            return;
        }

        if (event.key === 'End') {
            event.preventDefault();
            const items = options();
            const last = items[items.length - 1];
            if (last) {
                setActive(last.id);
            }

            return;
        }

        if (event.key === 'Enter') {
            event.preventDefault();
            followActive();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.defaultPrevented) {
            return;
        }

        const chord = (event.ctrlKey || event.metaKey) && ! event.altKey && event.key.toLowerCase() === 'k';

        if (isOpen()) {
            if (event.key === 'Escape' || chord) {
                event.preventDefault();
                close();
            }

            return;
        }

        if (! chord) {
            return;
        }

        // `/` and `g` stay off while typing; Ctrl/⌘+K is the dedicated jump and
        // still opens from a field. The confirm dialog keeps the keyboard.
        if (confirmOpen()) {
            return;
        }

        event.preventDefault();
        open();
    });

    window.PlanePalette = { open, close };
})();
