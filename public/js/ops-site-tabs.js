(function () {
    "use strict";

    const tablist = document.querySelector("[data-site-tabs]");
    if (!tablist || tablist.dataset.enhanced === "true") {
        return;
    }

    const tabs = Array.prototype.slice.call(tablist.querySelectorAll('[role="tab"]'));
    const panels = Array.prototype.slice.call(document.querySelectorAll("[data-site-panel]"));
    if (!tabs.length || !panels.length) {
        return;
    }

    tablist.dataset.enhanced = "true";

    const panelIds = panels.map(function (panel) {
        return panel.id;
    });

    function panelExists(id) {
        return id !== "" && panelIds.indexOf(id) !== -1;
    }

    function activate(id, options) {
        const settings = options || {};
        const updateHash = settings.updateHash !== false;
        const scroll = settings.scroll === true;
        const nextId = panelExists(id) ? id : panels[0].id;

        tabs.forEach(function (tab) {
            const active = tab.getAttribute("aria-controls") === nextId;
            tab.classList.toggle("is-active", active);
            tab.setAttribute("aria-selected", active ? "true" : "false");
            tab.tabIndex = active ? 0 : -1;
        });

        panels.forEach(function (panel) {
            panel.hidden = panel.id !== nextId;
        });

        if (updateHash) {
            history.replaceState(null, "", "#" + nextId);
        }

        if (scroll) {
            const panel = document.getElementById(nextId);
            if (panel && typeof panel.scrollIntoView === "function") {
                panel.scrollIntoView({ block: "start" });
            }
        }
    }

    tabs.forEach(function (tab, index) {
        tab.addEventListener("click", function (event) {
            event.preventDefault();
            activate(tab.getAttribute("aria-controls"), { scroll: false });
        });

        tab.addEventListener("keydown", function (event) {
            if (["ArrowLeft", "ArrowRight", "Home", "End"].indexOf(event.key) === -1) {
                return;
            }

            event.preventDefault();
            const last = tabs.length - 1;
            const targetIndex = event.key === "Home"
                ? 0
                : event.key === "End"
                    ? last
                    : (index + (event.key === "ArrowRight" ? 1 : -1) + tabs.length) % tabs.length;
            tabs[targetIndex].focus();
            activate(tabs[targetIndex].getAttribute("aria-controls"), { scroll: false });
        });
    });

    document.addEventListener("click", function (event) {
        const link = event.target.closest('a[href^="#"]');
        if (!link || tablist.contains(link)) {
            return;
        }

        const id = (link.getAttribute("href") || "").replace(/^#/, "");
        if (!panelExists(id)) {
            return;
        }

        event.preventDefault();
        activate(id, { scroll: false });
    });

    window.addEventListener("hashchange", function () {
        activate(location.hash.replace(/^#/, ""), { updateHash: false, scroll: false });
    });

    const initial = location.hash.replace(/^#/, "");
    activate(initial, { updateHash: panelExists(initial), scroll: false });
})();
