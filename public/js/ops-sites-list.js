(() => {
    'use strict';

    const form = document.querySelector('[data-ops-list-toolbar]');
    if (! (form instanceof HTMLFormElement)) {
        return;
    }

    const search = form.querySelector('input[name="q"]');
    let timer = 0;

    search?.addEventListener('input', () => {
        window.clearTimeout(timer);
        timer = window.setTimeout(() => {
            form.requestSubmit();
        }, 300);
    });

    form.querySelectorAll('select[data-ops-list-filter]').forEach((select) => {
        select.addEventListener('change', () => form.requestSubmit());
    });
})();
