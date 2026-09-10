<script>
    (() => {
        const root = document.querySelector('[data-site-tabs]');
        const tabs = [...document.querySelectorAll('[data-site-tabs] [role="tab"]')];
        const panels = [...document.querySelectorAll('[data-site-panel]')];
        if (! tabs.length || ! panels.length) return;

        const activate = (id, updateHash = true) => {
            if (! panels.some((panel) => panel.id === id)) id = panels[0].id;
            tabs.forEach((tab) => {
                const active = tab.getAttribute('aria-controls') === id;
                tab.classList.toggle('is-active', active);
                tab.setAttribute('aria-selected', active ? 'true' : 'false');
                tab.tabIndex = active ? 0 : -1;
            });
            panels.forEach((panel) => { panel.hidden = panel.id !== id; });
            if (updateHash) history.replaceState(null, '', `#${id}`);
        };

        tabs.forEach((tab, index) => {
            tab.addEventListener('click', (event) => {
                event.preventDefault();
                activate(tab.getAttribute('aria-controls'));
            });
            tab.addEventListener('keydown', (event) => {
                if (! ['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
                event.preventDefault();
                const target = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
                tabs[target].focus();
                activate(tabs[target].getAttribute('aria-controls'));
            });
        });

        document.addEventListener('click', (event) => {
            const link = event.target.closest('a[href^="#"]');
            if (! link || link.getAttribute('role') === 'tab') return;
            const id = (link.getAttribute('href') || '').replace(/^#/, '');
            if (! id || ! panels.some((panel) => panel.id === id)) return;
            event.preventDefault();
            activate(id);
        });

        const initial = (root && root.getAttribute('data-initial-tab')) || location.hash.slice(1);
        activate(initial, false);
    })();
</script>
