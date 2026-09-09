(() => {
    'use strict';

    const modal = document.querySelector('[data-ops-confirm-modal]');
    if (! modal) {
        return;
    }

    const panel = modal.querySelector('[data-ops-confirm-panel]');
    const titleEl = modal.querySelector('[data-ops-confirm-title]');
    const messageEl = modal.querySelector('[data-ops-confirm-message]');
    const acceptBtn = modal.querySelector('[data-ops-confirm-accept]');
    const cancelTriggers = modal.querySelectorAll('[data-ops-confirm-cancel]');

    /** @type {((value: boolean) => void)|null} */
    let resolver = null;
    /** @type {HTMLElement|null} */
    let previousFocus = null;

    const close = (result) => {
        modal.hidden = true;
        document.body.style.overflow = '';

        if (previousFocus instanceof HTMLElement) {
            previousFocus.focus();
        }

        const resolve = resolver;
        resolver = null;
        previousFocus = null;
        resolve?.(result);
    };

    /**
     * @param {{
     *   title?: string,
     *   message?: string,
     *   confirmLabel?: string,
     *   danger?: boolean,
     * }} options
     * @returns {Promise<boolean>}
     */
    const ask = (options = {}) => {
        const title = options.title || 'Confirm';
        const message = options.message || 'Do you want to continue?';
        const confirmLabel = options.confirmLabel || (options.danger === false ? 'Confirm' : 'Delete');
        const danger = options.danger !== false;

        titleEl.textContent = title;
        messageEl.textContent = message;
        acceptBtn.textContent = confirmLabel;
        acceptBtn.classList.toggle('btn-danger', danger);
        acceptBtn.classList.toggle('btn-primary', ! danger);

        previousFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
        panel?.focus();

        return new Promise((resolve) => {
            resolver = resolve;
        });
    };

    cancelTriggers.forEach((trigger) => {
        trigger.addEventListener('click', () => close(false));
    });

    acceptBtn.addEventListener('click', () => close(true));

    modal.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            close(false);
        }

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

    /**
     * @param {HTMLFormElement} form
     */
    const isDeleteForm = (form) => {
        const methodInput = form.querySelector('input[name="_method"]');

        return methodInput instanceof HTMLInputElement
            && methodInput.value.toUpperCase() === 'DELETE';
    };

    /**
     * @param {HTMLFormElement} form
     */
    const readConfirmOptions = (form) => {
        const explicitMessage = form.dataset.confirm || '';
        const isDelete = isDeleteForm(form);

        return {
            title: form.dataset.confirmTitle || (isDelete ? 'Delete site' : 'Confirm'),
            message: explicitMessage || (isDelete
                ? 'Soft-delete this site? Coolify is not contacted.'
                : 'Do you want to continue?'),
            confirmLabel: form.dataset.confirmLabel || (isDelete ? 'Delete' : 'Confirm'),
            danger: form.dataset.confirmDanger !== 'false',
        };
    };

    /**
     * @param {HTMLFormElement} form
     */
    const needsConfirmation = (form) => {
        if (form.dataset.confirmSkip === 'true') {
            return false;
        }

        if (form.dataset.confirm || form.hasAttribute('data-confirm')) {
            return true;
        }

        return isDeleteForm(form);
    };

    document.addEventListener('submit', async (event) => {
        const form = event.target;
        if (! (form instanceof HTMLFormElement)) {
            return;
        }

        if (form.dataset.confirmApproved === 'true') {
            delete form.dataset.confirmApproved;

            return;
        }

        if (! needsConfirmation(form)) {
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();

        const confirmed = await ask(readConfirmOptions(form));
        if (! confirmed) {
            return;
        }

        form.dataset.confirmApproved = 'true';
        form.requestSubmit();
    }, true);

    window.PlaneConfirm = { ask };
})();
