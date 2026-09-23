(() => {
    "use strict";

    /**
     * Sites filter panel: open/close with a remembered preference, keep the
     * active-filter badge in step with the list URL after every in-place region
     * update, and swap in the preset segment the region brought along.
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
    const contracts = window.PlaneOpsContracts;

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

    /**
     * The segment scrolls sideways instead of wrapping the toolbar; a fade marks
     * each edge that hides more presets, and the active one is kept in view.
     */
    const watchSegment = function () {
        const wrap = root.querySelector("[data-sites-segment-wrap]");
        const strip = wrap ? wrap.querySelector("[data-sites-segment]") : null;
        if (!wrap || !strip || !contracts || typeof contracts.scrollEdges !== "function") {
            return;
        }

        const edges = function () {
            const state = contracts.scrollEdges(strip.scrollLeft, strip.scrollWidth, strip.clientWidth);
            wrap.classList.toggle("has-more-start", state.start);
            wrap.classList.toggle("has-more-end", state.end);
        };

        const active = strip.querySelector(".plane-segment-item.is-active");
        if (active && strip.scrollWidth > strip.clientWidth) {
            // The strip is position: relative, so offsetLeft is measured from its edge.
            const from = active.offsetLeft;
            const to = from + active.offsetWidth;
            if (from < strip.scrollLeft || to > strip.scrollLeft + strip.clientWidth) {
                strip.scrollLeft = Math.max(0, from - 24);
            }
        }

        strip.addEventListener("scroll", edges, { passive: true });
        if (typeof window.ResizeObserver === "function") {
            new window.ResizeObserver(edges).observe(strip);
        } else {
            window.addEventListener("resize", edges);
        }
        edges();
    };

    /** A fetched region carries the server-marked segment; move it into the toolbar. */
    const swapSegment = function () {
        const next = root.querySelector("[data-ops-list-region] template[data-sites-segment-next]");
        const current = root.querySelector("[data-sites-segment-wrap]");
        if (!next) {
            return;
        }
        const fresh = next.content.querySelector("[data-sites-segment-wrap]");
        next.remove();
        if (!current || !fresh) {
            return;
        }

        // Keep keyboard focus on the same preset when the operator used one.
        const focused = document.activeElement && current.contains(document.activeElement)
            ? document.activeElement.getAttribute("data-ops-list-view")
            : null;
        const scrollLeft = (current.querySelector("[data-sites-segment]") || {}).scrollLeft || 0;

        current.replaceWith(fresh);
        const strip = fresh.querySelector("[data-sites-segment]");
        if (strip) {
            strip.scrollLeft = scrollLeft;
        }
        watchSegment();

        if (focused) {
            const again = fresh.querySelector("[data-ops-list-view=\"" + focused.replace(/"/g, "") + "\"]");
            if (again) {
                again.focus();
            }
        }
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

    watchSegment();

    root.addEventListener("ops:list-updated", function (event) {
        swapSegment();
        sync(event.detail && event.detail.url);
    });

    window.addEventListener("popstate", function () {
        sync(window.location.href);
    });
})();
