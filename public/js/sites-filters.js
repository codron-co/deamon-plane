(() => {
    "use strict";

    /**
     * Sites filter panel: open/close with a remembered preference, and keep the
     * active-filter badge and quick-filter segment in step with the list URL after
     * every in-place region update.
     */
    const root = document.querySelector("[data-sites-list]");
    if (!root) {
        return;
    }

    const STORAGE_KEY = "plane-sites-filter-panel";
    const FILTER_KEYS = ["channel", "status", "publish", "theme", "theme_id", "cms", "health", "stale", "app", "deploy", "auto_deploy", "server", "agent", "pack"];
    const toggle = root.querySelector("[data-sites-filter-toggle]");
    const panel = root.querySelector("[data-sites-filter-panel]");
    const count = root.querySelector("[data-sites-filter-count]");
    const quickLinks = Array.prototype.slice.call(root.querySelectorAll("[data-sites-quick-params]"));

    const readStored = function () {
        try {
            return window.localStorage.getItem(STORAGE_KEY) === "open";
        } catch (error) {
            return false;
        }
    };

    const writeStored = function (open) {
        try {
            window.localStorage.setItem(STORAGE_KEY, open ? "open" : "closed");
        } catch (error) {
            /* Storage is a convenience; the panel still works without it. */
        }
    };

    const setOpen = function (open) {
        if (!toggle || !panel) {
            return;
        }
        panel.hidden = !open;
        toggle.setAttribute("aria-expanded", open ? "true" : "false");
    };

    const activeFilters = function (url) {
        const active = {};
        FILTER_KEYS.concat(["q"]).forEach(function (key) {
            const value = (url.searchParams.get(key) || "").trim();
            if (value !== "") {
                active[key] = value;
            }
        });
        return active;
    };

    const sameFilters = function (left, right) {
        const leftKeys = Object.keys(left);
        if (leftKeys.length !== Object.keys(right).length) {
            return false;
        }
        return leftKeys.every(function (key) {
            return right[key] === left[key];
        });
    };

    const sync = function (href) {
        const url = new URL(href || window.location.href, window.location.origin);
        const active = activeFilters(url);
        const panelCount = FILTER_KEYS.filter(function (key) {
            return active[key] !== undefined;
        }).length;

        if (count) {
            count.textContent = String(panelCount);
            count.hidden = panelCount === 0;
        }
        if (toggle) {
            toggle.classList.toggle("is-active", panelCount > 0);
        }

        quickLinks.forEach(function (link) {
            let params = {};
            try {
                params = JSON.parse(link.getAttribute("data-sites-quick-params") || "{}");
            } catch (error) {
                params = {};
            }
            const on = sameFilters(active, params);
            link.classList.toggle("is-active", on);
            if (on) {
                link.setAttribute("aria-current", "true");
            } else {
                link.removeAttribute("aria-current");
            }
        });
    };

    if (toggle && panel) {
        setOpen(readStored());
        toggle.addEventListener("click", function () {
            const open = panel.hidden;
            setOpen(open);
            writeStored(open);
        });
        document.addEventListener("keydown", function (event) {
            if (event.key === "Escape" && !panel.hidden && panel.contains(document.activeElement)) {
                setOpen(false);
                writeStored(false);
                toggle.focus();
            }
        });
    }

    root.addEventListener("ops:list-updated", function (event) {
        sync(event.detail && event.detail.url);
    });

    window.addEventListener("popstate", function () {
        sync(window.location.href);
    });
})();
