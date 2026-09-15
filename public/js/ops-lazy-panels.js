(function () {
    "use strict";

    const panels = Array.prototype.slice.call(document.querySelectorAll("[data-lazy-panel]"));
    if (!panels.length) {
        return;
    }

    const load = async function (panel) {
        const state = panel.getAttribute("data-lazy-state") || "idle";
        if (state === "loading" || state === "loaded") {
            return;
        }
        panel.setAttribute("data-lazy-state", "loading");
        panel.setAttribute("aria-busy", "true");
        const error = panel.querySelector("[data-lazy-error]");
        const loading = panel.querySelector("[data-lazy-loading]");
        if (error) {
            error.hidden = true;
        }
        if (loading) {
            loading.hidden = false;
        }

        try {
            const response = await fetch(panel.getAttribute("data-lazy-url"), {
                headers: { Accept: "text/html", "X-Requested-With": "XMLHttpRequest" },
                credentials: "same-origin",
            });
            if (!response.ok) {
                throw new Error("HTTP " + response.status);
            }
            panel.innerHTML = await response.text();
            panel.setAttribute("data-lazy-state", "loaded");
            panel.setAttribute("aria-busy", "false");
            if (window.PlaneUI && typeof window.PlaneUI.refresh === "function") {
                window.PlaneUI.refresh(panel);
            }
        } catch (err) {
            panel.setAttribute("data-lazy-state", "failed");
            panel.setAttribute("aria-busy", "false");
            if (loading) {
                loading.hidden = true;
            }
            if (error) {
                error.hidden = false;
            }
        }
    };

    const loadVisible = function () {
        panels.forEach(function (panel) {
            const section = panel.closest("[data-site-panel], [data-ops-panel]");
            if (!section || !section.hidden) {
                load(panel);
            }
        });
    };

    document.addEventListener("click", function (event) {
        const retry = event.target instanceof Element ? event.target.closest("[data-lazy-retry]") : null;
        const panel = retry ? retry.closest("[data-lazy-panel]") : null;
        if (panel) {
            panel.setAttribute("data-lazy-state", "idle");
            load(panel);
        }
    });

    document.addEventListener("ops:tab-activated", function (event) {
        const id = event.detail ? event.detail.id : "";
        panels.forEach(function (panel) {
            const section = panel.closest("[data-site-panel], [data-ops-panel]");
            if (section && section.id === id) {
                load(panel);
            }
        });
    });

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", function () {
            window.setTimeout(loadVisible, 0);
        });
    } else {
        window.setTimeout(loadVisible, 0);
    }
})();
